<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\AtomicCounterStore;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\CacheLayerTtlStore;
use Infocyph\Foundation\Auth\Support\ArrayTtlStore;
use Infocyph\Foundation\Communication\CacheLayerWebhookReplayStore;
use Infocyph\Foundation\Security\Risk\RiskAuthority;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskHashBulkhead;
use Infocyph\Foundation\Security\Risk\RiskProofStore;
use Infocyph\Foundation\Security\Risk\RiskTelemetry;

it('aligns nullable string pull defaults across memory and atomic TTL stores', function (): void {
    $stores = [
        new ArrayTtlStore(),
        new CacheLayerTtlStore(Cache::memory(
            namespace: 'foundation-ttl-pull-contract-' . bin2hex(random_bytes(6)),
            options: new CacheOptions(failOpen: false),
        )),
    ];

    foreach ($stores as $store) {
        expect($store->pull('absent'))->toBeNull()
            ->and($store->pull('absent', '__miss__'))->toBe('__miss__');

        $store->put('payload', ['id' => 'one'], 30);
        expect($store->pull('payload', '__miss__'))->toBe(['id' => 'one'])
            ->and($store->pull('payload', '__miss__'))->toBe('__miss__');

        foreach ([false, true, 1.25, -7, null, ['nested' => [false, 1.25]]] as $value) {
            $store->put('mixed-payload', $value, 30);
            expect($store->get('mixed-payload'))->toBe($value)
                ->and($store->pull('mixed-payload'))->toBe($value);
        }
        foreach ([false, 1.25, -7, ['absent' => true]] as $default) {
            expect($store->get('absent', $default))->toBe($default);
        }
    }
});

it('uses CacheLayer native claim consume CAS and TTL semantics', function (): void {
    $cache = Cache::memory(
        namespace: 'foundation-atomic-atomic',
        options: new CacheOptions(failOpen: false),
    );
    $atomic = $cache->atomic();

    if ($atomic === null) {
        throw new \UnexpectedValueException('Memory cache requires an atomic counter store.');
    }
    expect($atomic)->not->toBeNull();

    $replay = new CacheLayerWebhookReplayStore($cache);
    expect($replay->claim('payments', 'delivery:1', 30))->toBeTrue()
        ->and($replay->claim('payments', 'delivery:1', 30))->toBeFalse();

    $ttl = new CacheLayerTtlStore($cache);
    $ttl->put('mfa:challenge:one', ['id' => 'one'], 30);
    expect($ttl->pull('mfa:challenge:one'))->toBe(['id' => 'one'])
        ->and($ttl->pull('mfa:challenge:one', '__miss__'))->toBe('__miss__');

    expect($atomic->setIfAbsent('cas-key', 0, 30))->toBeTrue()
        ->and($atomic->compareAndSet('cas-key', 0, 1, 30))->toBeTrue()
        ->and($atomic->compareAndSet('cas-key', 0, 2, 30))->toBeFalse()
        ->and($cache->get('cas-key'))->toBe(1);

    $ttl->put('ttl:expires', 'short-lived', 1);
    usleep(2_000_000);
    expect($ttl->get('ttl:expires', '__expired__'))->toBe('__expired__');
});

it('fails composition when the selected CacheLayer store cannot provide atomic state', function (): void {
    $cache = Cache::nullStore(new CacheOptions(failOpen: false));

    expect($cache->atomic())->toBeNull();

    try {
        new CacheLayerTtlStore($cache);
        throw new RuntimeException('Expected auth TTL composition to fail.');
    } catch (LogicException $failure) {
        expect($failure->getMessage())->toContain('atomic cache capability');
    }

    expect(fn() => new CacheLayerWebhookReplayStore($cache))
        ->toThrow(LogicException::class, 'atomic cache capability');
});

it('defers replay storage until a claim and preserves atomic failure behavior', function (): void {
    $calls = 0;
    $cache = Cache::memory(namespace: 'deferred-replay-' . bin2hex(random_bytes(4)), options: new CacheOptions(failOpen: false));
    $replay = new CacheLayerWebhookReplayStore(static function () use (&$calls, $cache) {
        ++$calls;
        return $cache;
    });
    expect($calls)->toBe(0);
    expect(fn() => $replay->claim('payments', 'invalid-ttl', 0))->toThrow(InvalidArgumentException::class);
    expect($calls)->toBe(0);
    expect($replay->claim('payments', 'one', 30))->toBeTrue()
        ->and($replay->claim('payments', 'one', 30))->toBeFalse()
        ->and($calls)->toBe(2);
    $unavailable = new CacheLayerWebhookReplayStore(static fn() => Cache::nullStore(new CacheOptions(failOpen: false)));
    expect(fn() => $unavailable->claim('payments', 'two', 30))->toThrow(LogicException::class, 'atomic cache capability');
});

it('preserves replay consume CAS and auth lockout atomicity under process contention', function (): void {
    $dsn = foundationCacheLayerAtomicRedisDsn();
    $namespace = 'foundation-atomic-' . bin2hex(random_bytes(8));
    $cache = Cache::redis(
        namespace: $namespace,
        dsn: $dsn,
        options: new CacheOptions(failOpen: false),
    );

    try {
        $cache->clear();

        $claims = foundationCacheLayerAtomicWorkers('claim', $namespace, $dsn, 'same-delivery', 8);
        expect(array_count_values($claims))->toMatchArray(['1' => 1, '0' => 7]);

        $ttl = new CacheLayerTtlStore($cache);
        $ttl->put('single-consume', 'payload', 30);
        $pulls = foundationCacheLayerAtomicWorkers('pull', $namespace, $dsn, 'single-consume', 8);
        expect(array_count_values($pulls))->toMatchArray(['payload' => 1, '__miss__' => 7]);

        $atomic = $cache->atomic();
        \PHPUnit\Framework\Assert::assertNotNull($atomic);
        expect($atomic->setIfAbsent('cas-contention', 0, 30))->toBeTrue();
        $cas = foundationCacheLayerAtomicWorkers('cas', $namespace, $dsn, 'cas-contention', 8);
        expect(array_count_values($cas))->toMatchArray(['1' => 1, '0' => 7])
            ->and($cache->get('cas-contention'))->toBe(1);

        $counter = new AtomicCounterStore(AtomicCounters::redis($namespace, $dsn));
        $counter->reset('login:shared-account');
        $increments = foundationCacheLayerAtomicWorkers('counter', $namespace, $dsn, 'login:shared-account', 8);
        expect($increments)->toBe(['1', '2', '3', '4', '5', '6', '7', '8']);
        $counter->reset('login:shared-account');

        $telemetry = new RiskTelemetry($cache, str_repeat('k', 32), shared: true, concentrationAt: 8);
        $context = RiskContext::fromTransport('concurrency', 'login', '192.0.2.1');
        $updates = foundationCacheLayerAtomicWorkers('risk-failure', $namespace, $dsn, 'same-account', 8);
        expect($updates)->toBe(array_fill(0, 8, '1'))
            ->and($telemetry->observe($context, 120, 'same-account'))->toBe([
                'familiar' => null, 'failedSignatureAttempts' => 8, 'concentrated' => true, 'reliable' => true,
            ]);

        $authority = new RiskAuthority(Cache::redis(
            namespace: $namespace,
            dsn: $dsn,
            options: new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false),
        ), str_repeat('k', 32), shared: true);
        $proofs = new RiskProofStore($authority, 'provider');
        $claims = foundationCacheLayerAtomicWorkers('risk-proof', $namespace, $dsn, 'shared-proof', 52);
        expect(array_count_values($claims))->toMatchArray(['1' => 1, '0' => 51])
            ->and($proofs->claimVerifiedProof('shared-proof'))->toBeFalse();

        $clearance = $proofs->issueClearance($context, 'account', 'verified-proof', 120);
        \PHPUnit\Framework\Assert::assertIsString($clearance);
        expect($proofs->issueClearance($context, 'account', 'verified-proof', 120))->toBeNull();
        $redeemed = foundationCacheLayerAtomicWorkers('risk-clearance', $namespace, $dsn, $clearance, 52);
        expect(array_count_values($redeemed))->toMatchArray(['1' => 1, '0' => 51])
            ->and($proofs->redeemClearance($context, 'account', $clearance, 120))->toBeFalse();

        $otherToken = $proofs->issueClearance($context, 'account', 'another-proof', 120);
        \PHPUnit\Framework\Assert::assertIsString($otherToken);
        expect($proofs->redeemClearance(RiskContext::fromTransport('other', 'login', '192.0.2.1'), 'account', $otherToken, 120))->toBeFalse()
            ->and($proofs->redeemClearance($context, 'account', $otherToken, 120))->toBeFalse();

        $bulkhead = new RiskHashBulkhead($authority, 'password-workers', 2, nonEvictingIsolatedPool: true);
        expect($bulkhead->acquire())->toBeNull()
            ->and($bulkhead->provisionDrainedPool())->toBeTrue();
        $leases = foundationCacheLayerAtomicWorkers('risk-hash-slot', $namespace, $dsn, 'password-workers', 52);
        expect(array_count_values($leases))->toMatchArray(['1' => 2, '0' => 50])
            ->and($bulkhead->acquire())->toBeNull()
            ->and($bulkhead->provisionDrainedPool())->toBeFalse();

        $expired = $proofs->issueClearance($context, 'account', 'expired-proof', 120);
        \PHPUnit\Framework\Assert::assertIsString($expired);
        expect($proofs->redeemClearance($context, 'account', $expired, 210))->toBeFalse();
    } finally {
        $cache->clear();
    }
});

it('keeps capacity closed when a real isolated owner process is killed', function (): void {
    $dsn = foundationCacheLayerAtomicRedisDsn();
    $namespace = 'foundation-risk-abort-' . bin2hex(random_bytes(8));
    $cache = Cache::redis(
        namespace: $namespace,
        dsn: $dsn,
        options: new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false),
    );
    $authority = new RiskAuthority($cache, str_repeat('k', 32), shared: true);
    $bulkhead = new RiskHashBulkhead($authority, 'abort-pool', 1, nonEvictingIsolatedPool: true);
    $worker = null;

    try {
        expect($bulkhead->provisionDrainedPool())->toBeTrue();
        $worker = foundationCacheLayerStartWorker([
            PHP_BINARY, dirname(__DIR__) . '/Fixtures/cachelayer_atomic_worker.php',
            'risk-hash-hold', $namespace, $dsn, 'abort-pool',
        ]);
        foundationCacheLayerReleaseWorkers([$worker]);
        $read = [$worker[2]];
        $write = null;
        $except = null;
        expect(stream_select($read, $write, $except, 15))->toBe(1)
            ->and(fgets($worker[2]))->toBe("held\n")
            ->and($bulkhead->acquire())->toBeNull();
        expect(proc_terminate($worker[0], 9))->toBeTrue();
        foundationCacheLayerWaitWorker($worker[0], time() + 15);
        $status = foundationCacheLayerCloseWorker($worker);
        $worker = null;
        expect($status)->not->toBe(0)
            ->and($bulkhead->acquire())->toBeNull()
            ->and($bulkhead->provisionDrainedPool())->toBeFalse();
    } finally {
        if ($worker !== null) {
            proc_terminate($worker[0], 9);
            foundationCacheLayerCloseWorker($worker);
        }
        // This namespace belongs exclusively to this completed test.
        $cache->clear();
    }
});

function foundationCacheLayerAtomicRedisDsn(): string
{
    $explicit = getenv('FOUNDATION_TEST_REDIS_DSN');
    if (is_string($explicit) && $explicit !== '') {
        return $explicit;
    }

    $host = (($__foundation154_117 = getenv('IC_REDIS_HOST')) !== false && $__foundation154_117 !== '' && $__foundation154_117 !== '0' ? $__foundation154_117 : '127.0.0.1');
    $port = (($__foundation154_118 = getenv('IC_REDIS_PORT')) !== false && $__foundation154_118 !== '' && $__foundation154_118 !== '0' ? $__foundation154_118 : '6379');
    $password = getenv('IC_REDIS_PASSWORD');
    $credentials = is_string($password) && $password !== ''
        ? ':' . rawurlencode($password) . '@'
        : '';

    return sprintf('redis://%s%s:%s', $credentials, $host, $port);
}

/**
 * @return list<string>
 * @throws \InvalidArgumentException
 * @throws \RuntimeException
 * @throws \JsonException
 */
function foundationCacheLayerAtomicWorkers(
    string $mode,
    string $namespace,
    string $dsn,
    string $argument,
    int $count,
): array {
    if ($count < 1 || $count > 64) {
        throw new InvalidArgumentException('Invalid concurrency worker count.');
    }
    $processes = [];
    $command = [PHP_BINARY, dirname(__DIR__) . '/Fixtures/cachelayer_atomic_worker.php', $mode, $namespace, $dsn, $argument];
    $results = [];

    try {
        for ($i = 0; $i < $count; ++$i) {
            $processes[] = foundationCacheLayerStartWorker($command);
        }
        foundationCacheLayerReleaseWorkers($processes);

        $deadline = time() + 30;
        foreach ($processes as $index => $worker) {
            foundationCacheLayerWaitWorker($worker[0], $deadline);
            $output = trim((string) stream_get_contents($worker[2]));
            $error = trim((string) stream_get_contents($worker[3]));
            $status = foundationCacheLayerCloseWorker($worker);
            unset($processes[$index]);
            if ($status !== 0) {
                throw new RuntimeException('CacheLayer concurrency worker failed: ' . $error);
            }
            $results[] = foundationCacheLayerWorkerResult(json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        }
    } finally {
        foreach ($processes as $worker) {
            proc_terminate($worker[0], 9);
            foundationCacheLayerCloseWorker($worker);
        }
    }

    sort($results, SORT_STRING);

    return $results;
}

/**
 * @param list<string> $command
 * @return array{resource,resource,resource,resource}
 * @throws \RuntimeException
 */
function foundationCacheLayerStartWorker(array $command): array
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start CacheLayer concurrency worker.');
    }

    return [$process, $pipes[0], $pipes[1], $pipes[2]];
}

/**
 * @param list<array{resource,resource,resource,resource}> $workers
 * @throws \RuntimeException
 */
function foundationCacheLayerReleaseWorkers(array $workers): void
{
    $deadline = time() + 30;
    foreach ($workers as [, , $stdout]) {
        $read = [$stdout];
        $write = null;
        $except = null;
        $remaining = max(0, $deadline - time());
        if (stream_select($read, $write, $except, $remaining) !== 1 || fgets($stdout) !== "ready\n") {
            throw new RuntimeException('CacheLayer concurrency worker readiness deadline exceeded.');
        }
    }
    foreach ($workers as [, $stdin]) {
        if (fwrite($stdin, "go\n") !== 3) {
            throw new RuntimeException('Unable to release CacheLayer worker start barrier.');
        }
    }
}

/**
 * @param resource $process
 * @throws \RuntimeException
 */
function foundationCacheLayerWaitWorker($process, int $deadline): void
{
    while (proc_get_status($process)['running']) {
        if (time() >= $deadline) {
            throw new RuntimeException('CacheLayer concurrency worker deadline exceeded.');
        }
        usleep(1_000);
    }
}

/** @param array{resource,resource,resource,resource} $worker */
function foundationCacheLayerCloseWorker(array $worker): int
{
    [$process, $stdin, $stdout, $stderr] = $worker;
    fclose($stdin);
    fclose($stdout);
    fclose($stderr);

    return proc_close($process);
}

/** @throws \RuntimeException */
function foundationCacheLayerWorkerResult(mixed $result): string
{
    return match (true) {
        $result === true => '1',
        $result === false => '0',
        is_int($result), is_string($result) => (string) $result,
        default => throw new RuntimeException('Unexpected CacheLayer concurrency worker result.'),
    };
}
