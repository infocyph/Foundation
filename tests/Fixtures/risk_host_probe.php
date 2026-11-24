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
use Infocyph\Webrick\Request\Support\IpCidr;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * @throws \Exception
 */
function foundationRiskHostCache(): CacheInterface
{
    return Cache::redis(
        namespace: 'foundation-risk-host-fixture',
        dsn: 'redis://127.0.0.1:16379',
        options: new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false),
    );
}

/**
 * @throws \InvalidArgumentException
 * @throws \LogicException
 */
function foundationRiskHostBulkhead(CacheInterface $cache): RiskHashBulkhead
{
    return new RiskHashBulkhead(new RiskAuthority($cache, str_repeat('k', 32), shared: true), 'fpm', 1, nonEvictingIsolatedPool: true);
}

/**
 * @throws \RuntimeException
 */
function foundationRiskHostOwner(mixed $owner): int
{
    if (!is_int($owner) || $owner < 0) {
        throw new RuntimeException('Invalid isolated fixture owner.');
    }

    return $owner;
}

if (PHP_SAPI === 'cli') {
    $cache = foundationRiskHostCache();
    $bulkhead = foundationRiskHostBulkhead($cache);
    $result = match ($argv[1] ?? '') {
        'provision' => $bulkhead->provisionDrainedPool(),
        'held' => foundationRiskHostOwner($cache->get('held-owner', 0)),
        'occupied' => $bulkhead->acquire() === null && !$bulkhead->provisionDrainedPool(),
        'clear' => $cache->clear(),
        default => throw new RuntimeException('Invalid host fixture command.'),
    };
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);

    exit(0);
}

if (PHP_SAPI !== 'fpm-fcgi' || ($_SERVER['REMOTE_ADDR'] ?? null) !== '127.0.0.1') {
    throw new RuntimeException('Isolated FPM fixture requires loopback FastCGI ingress.');
}
$path = match ($_SERVER['REQUEST_URI'] ?? null) {
    '/baseline' => 'baseline',
    '/login' => 'login',
    '/deny' => 'deny',
    '/challenge' => 'challenge',
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
if ($path === 'baseline') {
    $response = $render($authenticator->login($login));
    http_response_code($response->getStatusCode());
    header('Content-Type: application/json');
    file_put_contents('php://output', json_encode(['status' => $response->getStatusCode(), 'pid' => getmypid(), 'sapi' => PHP_SAPI], JSON_THROW_ON_ERROR));

    exit(0);
}

$cache = foundationRiskHostCache();
$bulkhead = foundationRiskHostBulkhead($cache);
if ($path === 'hold') {
    // The marker is reached inside the password-verifier boundary, after slot acquisition.
    ignore_user_abort(true);
    $verifier = new class ($services->passwordVerifier(), $cache) implements PasswordVerifierInterface {
        public function __construct(private readonly PasswordVerifierInterface $inner, private readonly CacheInterface $cache) {}

        /**
         * @throws \Psr\SimpleCache\InvalidArgumentException
         * @throws \RuntimeException
         */
        #[\Override]
        public function verify(string $plainPassword, string $storedHash): PasswordVerificationResult
        {
            if (!$this->cache->set('held-owner', getmypid(), 60)) {
                throw new RuntimeException('Unable to publish isolated fixture ownership.');
            }
            // Hold the dedicated fixture worker, not the ordinary Runwire event loop.
            if (time_nanosleep(20, 0) !== true) {
                throw new RuntimeException('Isolated fixture hold interrupted.');
            }

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
$fingerprint = 't13d1516h2_8daaf6152771_02713d6af862';
// Deterministic server-owned test signals, not a claim to measure the client JA4.
$policy = $path === 'challenge'
    ? new RiskPolicy(agentMarkers: [$fingerprint => ['ExpectedBrowser/']], unfamiliarWeight: 0)
    : new RiskPolicy();
$context = $path === 'challenge'
    ? RiskContext::fromTransport('fixture-challenge', 'login', '127.0.0.1', [IpCidr::from('127.0.0.1/32')], ['192.0.2.1'], [$fingerprint], [], ['FixtureBrowser/1'])
    : RiskContext::fromTransport('fixture', 'login', '127.0.0.1');
$guard = new RiskLogin($authenticator, $policy, new RiskTelemetry($cache, str_repeat('t', 32), shared: true), bulkhead: $bulkhead);
$response = $guard->handle(
    Request::fromGlobals(),
    $login,
    $context,
    time(),
    $render,
    hardDeny: $path === 'deny',
);

http_response_code($response->getStatusCode());
header('Content-Type: application/json');
if ($response->getHeaderLine('Cache-Control') !== 'no-store') {
    throw new RuntimeException('Protected response must not be cacheable.');
}
header('Cache-Control: no-store');
file_put_contents('php://output', json_encode(['status' => $response->getStatusCode(), 'pid' => getmypid(), 'sapi' => PHP_SAPI], JSON_THROW_ON_ERROR));
