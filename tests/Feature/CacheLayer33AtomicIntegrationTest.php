<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\CacheLayerTtlStore;
use Infocyph\Foundation\Communication\CacheLayerWebhookReplayStore;

it('uses CacheLayer native claim consume CAS and TTL semantics', function (): void {
    $cache = Cache::memory(
        namespace: 'foundation-33-atomic',
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

    expect($atomic?->setIfAbsent('cas-key', 0, 30))->toBeTrue()
        ->and($atomic?->compareAndSet('cas-key', 0, 1, 30))->toBeTrue()
        ->and($atomic?->compareAndSet('cas-key', 0, 2, 30))->toBeFalse()
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

it('allows exactly one replay claimant one consume recipient and one CAS winner under contention', function (): void {
    $dsn = foundationCacheLayer33RedisDsn();
    $namespace = 'foundation-33-' . bin2hex(random_bytes(8));
    $cache = Cache::redis(
        namespace: $namespace,
        dsn: $dsn,
        options: new CacheOptions(failOpen: false),
    );

    try {
        $cache->clear();

        $claims = foundationCacheLayer33Workers('claim', $namespace, $dsn, 'same-delivery', 8);
        expect(array_count_values($claims))->toMatchArray(['1' => 1, '0' => 7]);

        $ttl = new CacheLayerTtlStore($cache);
        $ttl->put('single-consume', 'payload', 30);
        $pulls = foundationCacheLayer33Workers('pull', $namespace, $dsn, 'single-consume', 8);
        expect(array_count_values($pulls))->toMatchArray(['payload' => 1, '__miss__' => 7]);

        $atomic = $cache->atomic();
        expect($atomic)->not->toBeNull()
            ->and($atomic?->setIfAbsent('cas-contention', 0, 30))->toBeTrue();
        $cas = foundationCacheLayer33Workers('cas', $namespace, $dsn, 'cas-contention', 8);
        expect(array_count_values($cas))->toMatchArray(['1' => 1, '0' => 7])
            ->and($cache->get('cas-contention'))->toBe(1);
    } finally {
        $cache->clear();
    }
});

function foundationCacheLayer33RedisDsn(): string
{
    $explicit = getenv('FOUNDATION_TEST_REDIS_DSN');
    if (is_string($explicit) && $explicit !== '') {
        return $explicit;
    }

    $host = getenv('IC_REDIS_HOST') ?: '127.0.0.1';
    $port = getenv('IC_REDIS_PORT') ?: '6379';
    $password = getenv('IC_REDIS_PASSWORD');
    $credentials = is_string($password) && $password !== ''
        ? ':' . rawurlencode($password) . '@'
        : '';

    return sprintf('redis://%s%s:%s', $credentials, $host, $port);
}

/** @return list<string> */
function foundationCacheLayer33Workers(
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
            is_string($decoded) => $decoded,
            default => throw new RuntimeException('Unexpected CacheLayer concurrency worker result.'),
        };
    }

    sort($results, SORT_STRING);

    return $results;
}
