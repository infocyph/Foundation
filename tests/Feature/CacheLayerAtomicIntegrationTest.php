<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\AtomicCounterStore;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\CacheLayerTtlStore;
use Infocyph\Foundation\Auth\Support\ArrayTtlStore;
use Infocyph\Foundation\Communication\CacheLayerWebhookReplayStore;

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
    }
});

it('uses CacheLayer native claim consume CAS and TTL semantics', function (): void {
    $cache = Cache::memory(
        namespace: 'foundation-atomic-atomic',
        options: new CacheOptions(failOpen: false),
    );
    $atomic = $cache->atomic();

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
        expect($atomic)->not->toBeNull()
            ->and($atomic?->setIfAbsent('cas-contention', 0, 30))->toBeTrue();
        $cas = foundationCacheLayerAtomicWorkers('cas', $namespace, $dsn, 'cas-contention', 8);
        expect(array_count_values($cas))->toMatchArray(['1' => 1, '0' => 7])
            ->and($cache->get('cas-contention'))->toBe(1);

        $counter = new AtomicCounterStore(AtomicCounters::redis($namespace, $dsn));
        $counter->reset('login:shared-account');
        $increments = foundationCacheLayerAtomicWorkers('counter', $namespace, $dsn, 'login:shared-account', 8);
        expect($increments)->toBe(['1', '2', '3', '4', '5', '6', '7', '8']);
        $counter->reset('login:shared-account');
    } finally {
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
 * @throws \RuntimeException
 */
function foundationCacheLayerAtomicWorkers(
    string $mode,
    string $namespace,
    string $dsn,
    string $argument,
    int $count,
): array {
    $processes = [];
    $worker = dirname(__DIR__) . '/Fixtures/cachelayer_atomic_worker.php';

    for ($i = 0; $i < $count; ++$i) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $worker, $mode, $namespace, $dsn, $argument],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start CacheLayer concurrency worker.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes[1], $pipes[2]];
    }

    $results = [];
    foreach ($processes as [$process, $stdout, $stderr]) {
        $output = trim((string) stream_get_contents($stdout));
        $error = trim((string) stream_get_contents($stderr));
        fclose($stdout);
        fclose($stderr);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException('CacheLayer concurrency worker failed: ' . $error);
        }
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $results[] = match (true) {
            $decoded === true => '1',
            $decoded === false => '0',
            is_int($decoded) => (string) $decoded,
            is_string($decoded) => $decoded,
            default => throw new RuntimeException('Unexpected CacheLayer concurrency worker result.'),
        };
    }

    sort($results, SORT_STRING);

    return $results;
}
