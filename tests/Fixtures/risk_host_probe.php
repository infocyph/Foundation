<?php

declare(strict_types=1);

namespace Infocyph;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\Login\LoginRequest;
use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerificationResult;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Security\Risk\RiskAuthority;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskHashBulkhead;
use Infocyph\Foundation\Security\Risk\RiskLogin;
use Infocyph\Foundation\Security\Risk\RiskPolicy;
use Infocyph\Foundation\Security\Risk\RiskTelemetry;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$cache = Cache::redis(
    namespace: 'foundation-risk-host-fixture',
    dsn: 'redis://127.0.0.1:16379',
    options: new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false),
);
$bulkhead = new RiskHashBulkhead(new RiskAuthority($cache, str_repeat('k', 32), shared: true), 'fpm', 1, nonEvictingIsolatedPool: true);

if (PHP_SAPI === 'cli') {
    $result = match ($argv[1] ?? '') {
        'provision' => $bulkhead->provisionDrainedPool(),
        'held' => $cache->get('held-owner', 0),
        'occupied' => $bulkhead->acquire() === null && !$bulkhead->provisionDrainedPool(),
        'clear' => $cache->clear(),
        default => throw new RuntimeException('Invalid host fixture command.'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;

    exit(0);
}

if (PHP_SAPI !== 'fpm-fcgi' || ($_SERVER['REMOTE_ADDR'] ?? null) !== '127.0.0.1') {
    throw new RuntimeException('Isolated FPM fixture requires loopback FastCGI ingress.');
}
$path = match ($_SERVER['REQUEST_URI'] ?? null) {
    '/baseline' => 'baseline',
    '/login' => 'login',
    '/deny' => 'deny',
    '/hold' => 'hold',
    default => throw new RuntimeException('Unknown host fixture route.'),
};
$hash = getenv('RISK_FIXTURE_HASH');
if (!is_string($hash) || $hash === '') {
    throw new RuntimeException('Missing fixture password hash.');
}
$app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
$services = $app->make(AuthServices::class);
$services->accounts()->create('fixture@example.test', $hash);
$authenticator = $app->make(Authenticator::class);
$login = new LoginRequest('fixture@example.test', 'fixture-password');
$render = static fn(LoginResult $result): Response => Response::json(['ok' => $result->authenticated()], $result->authenticated() ? 200 : 401);
if ($path === 'hold') {
    // The marker is reached inside the password-verifier boundary, after slot acquisition.
    ignore_user_abort(true);
    $verifier = new class ($services->passwordVerifier(), $cache) implements PasswordVerifierInterface {
        public function __construct(private readonly PasswordVerifierInterface $inner, private readonly CacheInterface $cache) {}

        #[\Override]
        public function verify(string $plainPassword, string $storedHash): PasswordVerificationResult
        {
            if (!$this->cache->set('held-owner', getmypid(), 60)) {
                throw new RuntimeException('Unable to publish isolated fixture ownership.');
            }
            sleep(20);

            return $this->inner->verify($plainPassword, $storedHash);
        }
    };
    $authenticator = new Authenticator(
        $services->accountProvider(),
        $app->make(AccountStoreInterface::class),
        $verifier,
        $services->sessions(),
        $app->make(AuthIdGeneratorInterface::class),
        $app->make(AuditEventStoreInterface::class),
        $services->lockouts(),
    );
}
if ($path === 'baseline') {
    $response = $render($authenticator->login($login));
} else {
    $guard = new RiskLogin($authenticator, new RiskPolicy(), new RiskTelemetry($cache, str_repeat('t', 32), shared: true), bulkhead: $bulkhead);
    $response = $guard->handle(
        Request::fromGlobals(),
        $login,
        RiskContext::fromTransport('fixture', 'login', '127.0.0.1'),
        time(),
        $render,
        hardDeny: $path === 'deny',
    );
}

http_response_code($response->getStatusCode());
header('Content-Type: application/json');
if ($path !== 'baseline') {
    if ($response->getHeaderLine('Cache-Control') !== 'no-store') {
        throw new RuntimeException('Protected response must not be cacheable.');
    }
    header('Cache-Control: no-store');
}
echo json_encode(['status' => $response->getStatusCode(), 'pid' => getmypid(), 'sapi' => PHP_SAPI], JSON_THROW_ON_ERROR);
