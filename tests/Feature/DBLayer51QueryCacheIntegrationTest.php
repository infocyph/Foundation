<?php

declare(strict_types=1);

use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Psr\Container\ContainerInterface;

/**
 * @return array{DBLayerFactory,RuntimeExecutionState,object,ConfigRepository}
 */
function foundationDbLayer51QueryRuntime(array $config): array
{
    $repository = new ConfigRepository($config);
    $state = new RuntimeExecutionState();
    $container = new class implements ContainerInterface {
        /** @var array<string,mixed> */
        public array $services = [];

        public bool $cacheFactoryTouched = false;

        public function get(string $id): mixed
        {
            if ($id === CacheLayerFactory::class) {
                $this->cacheFactoryTouched = true;
            }

            return $this->services[$id]
                ?? throw new RuntimeException(sprintf('Unknown test service "%s".', $id));
        }

        public function has(string $id): bool
        {
            if ($id === CacheLayerFactory::class) {
                $this->cacheFactoryTouched = true;
            }

            return array_key_exists($id, $this->services);
        }
    };
    $container->services[RuntimeExecutionState::class] = $state;

    $database = new DBLayerFactory(new DatabaseConnectionResolver($repository), $container);
    $paths = new PathManager((string) ($config['app']['base_path'] ?? sys_get_temp_dir()));
    $cache = new CacheLayerFactory(
        $repository,
        $paths,
        static fn(?string $name = null) => $database->infrastructureConnection($name),
    );
    $container->services[CacheLayerFactory::class] = $cache;

    return [$database, $state, $container, $repository];
}

it('keeps CacheLayer completely cold when DBLayer query caching is disabled', function (): void {
    [$database, $state, $container] = foundationDbLayer51QueryRuntime([
        'app' => ['base_path' => sys_get_temp_dir()],
        'database' => [
            'default' => 'default',
            'query_cache' => ['enabled' => false, 'store' => 'db-query'],
            'connections' => [
                'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
        'cache' => [
            'prefix' => 'foundation-test:',
            'stores' => ['db-query' => ['driver' => 'memory']],
        ],
    ]);

    $connection = $database->connection();

    expect($connection->hasQueryCache())->toBeFalse()
        ->and($container->cacheFactoryTouched)->toBeFalse();

    $state->cleanup();
});

it('requires an isolated CacheLayer namespace before enabling shared DB query caching', function (): void {
    $generic = new DatabaseConnectionResolver(new ConfigRepository([
        'database' => [
            'default' => 'default',
            'query_cache' => ['enabled' => true, 'store' => 'db-query'],
            'connections' => [
                'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
        'cache' => [
            'prefix' => 'foundation:cache:',
            'stores' => ['db-query' => ['driver' => 'memory']],
        ],
    ]));

    expect(fn() => $generic->queryCacheStore())
        ->toThrow(ConfigurationException::class, 'requires an isolated CacheLayer namespace');

    $prefixed = new DatabaseConnectionResolver(new ConfigRepository([
        'database' => [
            'query_cache' => ['enabled' => true, 'store' => 'db-query'],
        ],
        'cache' => [
            'prefix' => 'my-application:prod:',
            'stores' => ['db-query' => ['driver' => 'memory']],
        ],
    ]));
    expect($prefixed->queryCacheStore())->toBe('db-query');

    $explicit = new DatabaseConnectionResolver(new ConfigRepository([
        'database' => [
            'query_cache' => ['enabled' => true, 'store' => 'db-query'],
        ],
        'cache' => [
            'prefix' => 'foundation:cache:',
            'stores' => [
                'db-query' => [
                    'driver' => 'memory',
                    'namespace' => 'my-application.prod.db-query',
                ],
            ],
        ],
    ]));
    expect($explicit->queryCacheStore())->toBe('db-query');
});

it('uses the exact DBLayer connection cache and invalidates only after successful outer commit', function (): void {
    [$database, $state] = foundationDbLayer51QueryRuntime([
        'app' => ['base_path' => sys_get_temp_dir()],
        'database' => [
            'default' => 'default',
            'query_cache' => ['enabled' => true, 'store' => 'db-query'],
            'connections' => [
                'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
        'cache' => [
            'prefix' => 'foundation-db51-query-test:',
            'stores' => ['db-query' => ['driver' => 'memory']],
        ],
    ]);

    $connection = $database->connection();
    expect($connection->hasQueryCache())->toBeTrue();

    $connection->statement('CREATE TABLE query_items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $connection->table('query_items')->insert(['id' => 1, 'value' => 'one']);

    $read = static fn() => $connection
        ->table('query_items')
        ->where('id', '=', 1)
        ->cacheFor(60)
        ->cacheKey('query-item-1')
        ->first();

    expect($read()['value'] ?? null)->toBe('one');

    $connection->beginTransaction();
    $connection->table('query_items')->where('id', '=', 1)->update(['value' => 'rolled-back']);
    expect($connection->table('query_items')->where('id', '=', 1)->first()['value'] ?? null)
        ->toBe('rolled-back');
    $connection->rollbackTransaction();

    expect($read()['value'] ?? null)->toBe('one');

    $connection->beginTransaction();
    $connection->table('query_items')->where('id', '=', 1)->update(['value' => 'committed']);
    $connection->commitTransaction();

    expect($read()['value'] ?? null)->toBe('committed');

    $state->cleanup();
});

it('builds PDO-backed CacheLayer infrastructure without borrowing an execution connection', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-db51-cache-infra-' . bin2hex(random_bytes(6));
    mkdir($basePath, 0777, true);

    try {
        [$database, $state, $container] = foundationDbLayer51QueryRuntime([
            'app' => ['base_path' => $basePath],
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
            'cache' => [
                'prefix' => 'foundation-db51-infra-test:',
                'stores' => [
                    'database' => [
                        'driver' => 'pdo',
                        'connection' => 'default',
                        'namespace' => 'foundation-db51-infra-test.database',
                    ],
                ],
            ],
        ]);

        /** @var CacheLayerFactory $cache */
        $cache = $container->get(CacheLayerFactory::class);
        $cache->make('database');

        expect($state->hasDatabaseConnections())->toBeFalse();

        $applicationConnection = $database->connection();
        expect($applicationConnection)->not->toBe($database->infrastructureConnection());

        $state->cleanup();
    } finally {
        if (is_dir($basePath)) {
            rmdir($basePath);
        }
    }
});
