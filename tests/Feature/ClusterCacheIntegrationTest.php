<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterValue;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\AtomicCounterStore;
use Infocyph\Foundation\Foundation;
use Infocyph\DBLayer\Exceptions\TransactionException;

it('creates node cache stores and reports configured cluster status', function (): void {
    $app = foundationClusterCacheApplication();

    $cache = $app->cache()->store('catalog');
    $cache->set('product.42', 'cached');

    $status = $app->cache()->clusterStatus('catalog');

    expect($cache->get('product.42'))->toBe('cached')
        ->and($status->cluster)->toBe('catalog')
        ->and($status->nodeId)->toBe('node-a');
});

it('publishes cache invalidations through the transactional outbox only after commit', function (): void {
    $app = foundationClusterCacheApplication();
    $table = 'products_' . str_replace('.', '', uniqid('', true));
    $app->db()->connection()->statement('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

    $cluster = $app->cache()->cluster('catalog');
    $cluster->cache()->set('product.42', 'cached');

    $app->cache()->transactionalInvalidation(
        'catalog',
        static function ($connection, $outbox) use ($table): void {
            $connection->table($table)->insert(['id' => 42, 'name' => 'Ada']);
            $outbox->invalidateKey('product.42');
        },
    );

    expect($cluster->cache()->get('product.42'))->toBeNull()
        ->and($app->db()->table($table)->count())->toBe(1)
        ->and($cluster->status()->pendingEventCount)->toBe(1);
});

it('rolls back transactional outbox events without invalidating the local cache', function (): void {
    $app = foundationClusterCacheApplication();
    $cluster = $app->cache()->cluster('catalog');
    $cluster->cache()->set('product.42', 'cached');

    expect(static function () use ($app): mixed {
        return $app->cache()->transactionalInvalidation(
            'catalog',
            static function ($connection, $outbox): void {
                expect($connection->getPdo()->inTransaction())->toBeTrue();
                $outbox->invalidateKey('product.42');

                throw new RuntimeException('rollback');
            },
        );
    })->toThrow(TransactionException::class);

    expect($cluster->cache()->get('product.42'))->toBe('cached')
        ->and($cluster->status()->pendingEventCount)->toBe(0);
});

it('rejects unsafe cluster topology and non-atomic counter configuration', function (): void {
    $app = Foundation::create([
        'app' => ['base_path' => sys_get_temp_dir() . '/foundation-cache-policy-' . uniqid('', true)],
        'cache' => [
            'stores' => [
                'auth' => ['driver' => 'node', 'sqlite_file' => 'database/auth.sqlite'],
            ],
            'clusters' => [
                'auth' => [
                    'store' => 'auth',
                    'cluster' => 'auth',
                    'node_id' => '',
                    'transport' => 'events',
                ],
            ],
            'transports' => [
                'events' => ['driver' => 'pdo', 'connection' => 'sqlite'],
            ],
            'counters' => [
                'auth' => ['driver' => 'node'],
            ],
        ],
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
    ]);

    $validation = $app->validateConfiguration();

    expect($validation->fails())->toBeTrue()
        ->and($validation->messages())->toContain('cache.clusters.auth.node_id must be a stable explicit instance identity.')
        ->and($validation->messages())->toContain('cache.clusters.auth cannot be used for auth, session, security, or idempotency state.')
        ->and($validation->messages())->toContain('cache.counters.auth must use Redis or Valkey for atomic increments.');
});

it('adapts CacheLayer atomic counters to auth lockout counters', function (): void {
    $backend = new class implements AtomicCounterStoreInterface
    {
        public int $lastTtl = 0;

        public string $lastKey = '';

        public int $lastBy = 0;

        public function decrement(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
        {
            $this->lastKey = $key;
            $this->lastBy = $by;
            $this->lastTtl = $ttlSeconds ?? 0;

            return new AtomicCounterValue(0, false);
        }

        public function delete(string $key): bool
        {
            $this->lastKey = $key;

            return true;
        }

        public function get(string $key): ?int
        {
            $this->lastKey = $key;

            return null;
        }

        public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
        {
            $this->lastKey = $key;
            $this->lastBy = $by;
            $this->lastTtl = $ttlSeconds ?? 0;

            return new AtomicCounterValue(6, true);
        }
    };

    $counters = new AtomicCounterStore($backend, 'auth:');

    expect($counters->increment('login.42', ttlSeconds: 900))->toBe(6)
        ->and($backend->lastTtl)->toBe(900);
});

function foundationClusterCacheApplication(): \Infocyph\Foundation\Application\Application
{
    $basePath = sys_get_temp_dir() . '/foundation-cluster-cache-' . uniqid('', true);
    mkdir($basePath . '/storage/cache', 0775, true);
    mkdir($basePath . '/database', 0775, true);

    return Foundation::create([
        'app' => ['base_path' => $basePath],
        'database' => [
            'default' => 'primary',
            'connections' => [
                'primary' => ['driver' => 'sqlite', 'database' => 'database/application.sqlite'],
            ],
        ],
        'cache' => [
            'default' => 'catalog',
            'stores' => [
                'catalog' => [
                    'driver' => 'node',
                    'namespace' => 'catalog',
                    'sqlite_file' => 'storage/cache/catalog.sqlite',
                ],
            ],
            'transports' => [
                'events' => [
                    'driver' => 'pdo',
                    'connection' => 'primary',
                    'allow_sqlite_for_testing' => true,
                ],
            ],
            'clusters' => [
                'catalog' => [
                    'store' => 'catalog',
                    'cluster' => 'catalog',
                    'node_id' => 'node-a',
                    'transport' => 'events',
                ],
            ],
        ],
    ]);
}
