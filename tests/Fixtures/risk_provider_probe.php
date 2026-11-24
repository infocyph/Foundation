<?php

declare(strict_types=1);

namespace Infocyph;

use Infocyph\Foundation\Security\Risk\RiskChallengeVerifier;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Client\HttpOutboundDispatcher;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DnsResolverInterface;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use RuntimeException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$expected = match ($mode) {
    'accept' => true,
    'reject' => false,
    'redirect', 'oversize', 'malformed', 'wrong-host', 'untrusted', 'timeout', 'cancel' => null,
    default => throw new RuntimeException('Unknown risk provider qualification mode.'),
};
$hostname = $mode === 'wrong-host' ? 'wrong.example.test' : 'verify.example.test';
$path = match ($mode) {
    'wrong-host', 'untrusted' => 'accept',
    default => $mode,
};

$loop = new SelectLoop();
$resolver = new class ($loop) implements DnsResolverInterface {
    public int $calls = 0;

    public function __construct(private readonly SelectLoop $loop) {}

    #[\Override]
    public function cancel(int $id): bool
    {
        return $this->loop->cancel($id);
    }

    /**
     * @param callable(list<string>,?\Throwable):void $completion
     * @throws \OverflowException
     */
    #[\Override]
    public function resolve(string $hostname, callable $completion, ?CancellationToken $token = null, ?RequestDeadline $deadline = null): int
    {
        ++$this->calls;

        return $this->loop->defer(static function () use ($hostname, $completion, $token, $deadline): void {
            if (!in_array($hostname, ['verify.example.test', 'wrong.example.test'], true)
                || ($token?->isCancelled() ?? false) || ($deadline?->expired() ?? false)) {
                $completion([], new RuntimeException('Unexpected fixture DNS request.'));

                return;
            }
            // This address exists only on loopback in the disposable network namespace.
            $completion(['93.184.216.34'], null);
        });
    }
};

$numeric = new Dialer($loop);
$dialer = new HappyEyeballsDialer($loop, $numeric, $resolver);
$dispatcher = new HttpOutboundDispatcher($loop, $dialer);
$provider = new RiskChallengeVerifier(
    'https://' . $hostname . '/' . $path . '?fixed=1',
    'fixture-secret',
    'app.example.test',
    'tenant',
    'login',
    timeoutSeconds: $mode === 'timeout' ? 1.0 : 5.0,
);
$context = RiskContext::fromTransport('tenant', 'login', '192.0.2.1');
$runtime = new CoroutineRuntime($loop);
$request = RequestContext::standalone();
$cancelled = false;
$result = null;

try {
    if ($mode === 'cancel') {
        $loop->delay(0.5, static fn(): bool => $request->cancel(CancellationReason::HOST_CANCELLED));
    }

    try {
        $result = $runtime->runRequest($request, static fn(CoroutineScope $scope): ?bool => $provider->verify(
            $scope,
            $dispatcher,
            $context,
            'proof&value',
        ));
    } catch (CancelledException $error) {
        if ($mode !== 'cancel') {
            throw $error;
        }
        $cancelled = true;
    }

    if ($result !== $expected || $cancelled !== ($mode === 'cancel')
        || $resolver->calls !== 1 || $numeric->pendingCount() !== 0 || $dialer->pendingCount() !== 0) {
        throw new RuntimeException('Risk provider wire qualification failed: ' . $mode);
    }
} finally {
    $dialer->close();
    $numeric->close();
    $request->complete();
}

fwrite(STDOUT, 'Risk provider wire verified: ' . $mode . PHP_EOL);
