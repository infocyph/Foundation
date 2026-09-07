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

it('keeps failed atomic operations fail closed', function (): void {
    $cache = Cache::memory(
        namespace: 'foundation-33-fail-closed',
        options: new CacheOptions(failOpen: false),
    );
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull()
        ->and($atomic?->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($atomic?->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($cache->get('claim'))->toBe('first');
});

function foundationCacheLayer33RedisDsn(): string
{
    $dsn = getenv('REDIS_URL');
    if (is_string($dsn) && $dsn !== '') {
        return $dsn;
    }

    return 'redis://127.0.0.1:6379';
}

/** @return list<string> */
function foundationCacheLayer33Workers(
    string $operation,
    string $namespace,
    string $dsn,
    string $key,
    int $workers,
): array {
    $script = dirname(__DIR__) . '/Fixtures/cachelayer-33-worker.php';
    $processes = [];
    for ($worker = 0; $worker < $workers; ++$worker) {
        $command = [PHP_BINARY, $script, $operation, $namespace, $dsn, $key];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start CacheLayer atomic contention worker.');
        }
        $processes[] = [$process, $pipes];
    }

    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException(sprintf(
                'CacheLayer contention worker failed with exit %d: %s',
                $exit,
                trim((string) $stderr),
            ));
        }
        $results[] = trim((string) $stdout);
    }

    return $results;
}
