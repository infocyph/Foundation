<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Migration\SeedContext;
use Infocyph\DBLayer\Migration\Seeder;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DatabaseMigrationManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Testing\TestKit;

final class FoundationExampleMigration implements Migration
{
    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        $context->checkpoint();
        $schema->dropIfExists('foundation_examples');
    }

    public function id(): string
    {
        return '20260730000000_create_foundation_examples';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $context->checkpoint();
        $schema->create('foundation_examples', static function (Blueprint $table): void {
            $table->increments();
            $table->string('name');
        });
    }
}

final class FoundationExampleSeeder implements Seeder
{
    public function run(Connection $connection, SeedContext $context): void
    {
        expect($context->connection())->toBe($connection);
        $connection->statement('INSERT INTO foundation_examples (name) VALUES (?)', ['seeded']);
    }
}

it('runs the auth schema through DBLayer migrations', function (): void {
    $tables = new AuthTables();
    $schema = new AuthSchema($tables);
    $connection = DB::addConnection(new ConnectionConfig([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]), 'auth-schema');
    $runner = new MigrationRunner($connection, [$schema]);

    try {
        expect($runner->run())->toBe([$schema->id()])
            ->and($runner->status())->toBe([[
                'id' => $schema->id(),
                'applied' => true,
                'batch' => 1,
            ]]);

        $manager = new SchemaManager($connection);
        foreach ($tables->all() as $table) {
            expect($manager->hasTable($table))->toBeTrue();
        }

        expect($runner->reset(true))->toBe([$schema->id()]);
        foreach ($tables->all() as $table) {
            expect($manager->hasTable($table))->toBeFalse();
        }
    } finally {
        DB::purge();
    }
});

it('runs configured migrations seeders and database test transactions', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-migrations-' . bin2hex(random_bytes(6));
    mkdir($basePath . '/database', 0775, true);
    $app = Foundation::cli([
        'app' => ['base_path' => $basePath],
        'database' => [
            'default' => 'testing',
            'connections' => [
                'testing' => [
                    'driver' => 'sqlite',
                    'database' => 'database/testing.sqlite',
                ],
            ],
            'migrations' => [
                'classes' => [FoundationExampleMigration::class],
                'table' => 'migrations',
                'lock_store' => null,
                'lock_wait_seconds' => 0.0,
                'lock_lease_seconds' => 30.0,
            ],
            'seeders' => [FoundationExampleSeeder::class],
        ],
    ]);

    try {
        $factory = $app->make(DBLayerFactory::class);
        $migrations = $app->make(DatabaseMigrationManager::class);
        $connection = $factory->connection();
        $runner = $migrations->runner();
        $testing = $app->make(TestKit::class);

        expect($runner->run())->toBe(['20260730000000_create_foundation_examples'])
            ->and($migrations->seed())->toBe(1)
            ->and($connection->select('SELECT name FROM foundation_examples ORDER BY id'))
            ->toBe([['name' => 'seeded']]);

        $result = $testing->database()->transaction(
            function () use ($connection): string {
                $connection->statement(
                    'INSERT INTO foundation_examples (name) VALUES (?)',
                    ['temporary'],
                );

                return 'completed';
            },
        );

        expect($result)->toBe('completed')
            ->and($connection->select('SELECT name FROM foundation_examples ORDER BY id'))
            ->toBe([['name' => 'seeded']])
            ->and($testing->database()->refresh())
            ->toBe(['20260730000000_create_foundation_examples']);
    } finally {
        DB::purge();
        foundationDatabaseRemove($basePath);
    }
});

it('uses DBLayer 4.1 pretend output without mutating schema and rolls back exact batches', function (): void {
    $connection = new Connection(new ConnectionConfig([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $runner = new MigrationRunner($connection, [new FoundationExampleMigration()]);
    $schema = new SchemaManager($connection);

    $preview = $runner->pretend();

    expect($preview)->toHaveKey('20260730000000_create_foundation_examples')
        ->and($preview['20260730000000_create_foundation_examples'])->not->toBeEmpty()
        ->and($preview['20260730000000_create_foundation_examples'][0])->toHaveKeys(['sql', 'bindings'])
        ->and($schema->hasTable('foundation_examples'))->toBeFalse()
        ->and($runner->run(step: true))->toBe(['20260730000000_create_foundation_examples'])
        ->and($runner->status()[0]['batch'])->toBe(1)
        ->and($runner->rollbackBatch(1))->toBe(['20260730000000_create_foundation_examples'])
        ->and($schema->hasTable('foundation_examples'))->toBeFalse();
});

it('requires explicit authorization for destructive migration operations', function (): void {
    $connection = new Connection(new ConnectionConfig([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $runner = new MigrationRunner($connection, [new FoundationExampleMigration()]);
    $schema = new SchemaManager($connection);

    expect(fn() => $runner->fresh())
        ->toThrow(MigrationException::class, 'requires explicit authorization')
        ->and($runner->fresh(true))->toBe(['20260730000000_create_foundation_examples'])
        ->and($schema->hasTable('foundation_examples'))->toBeTrue()
        ->and(fn() => $runner->refresh())
        ->toThrow(MigrationException::class, 'requires explicit authorization')
        ->and($runner->refresh(true))->toBe(['20260730000000_create_foundation_examples'])
        ->and(fn() => $runner->reset())
        ->toThrow(MigrationException::class, 'requires explicit authorization')
        ->and($runner->reset(true))->toBe(['20260730000000_create_foundation_examples'])
        ->and($schema->hasTable('foundation_examples'))->toBeFalse();
});

it('exposes scoped DBLayer connections while Foundation keeps composition policy', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-db-' . bin2hex(random_bytes(6));
    mkdir($basePath . '/database', 0775, true);

    $app = Foundation::web([
        'app' => ['base_path' => $basePath],
        'database' => [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'database' => 'database/foundation.sqlite',
                ],
            ],
        ],
    ]);

    try {
        $app->container()->withinScope('webrick.request', static function () use ($app): void {
            $connection = $app->make(Connection::class);
            expect($connection)->toBe($app->make(DBLayerFactory::class)->connection());

            $connection->statement(
                'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)',
            );
            $connection->statement(
                'INSERT INTO users (name, email) VALUES (?, ?)',
                ['Ada Lovelace', 'ada@example.test'],
            );
            $fetched = $connection->withQueryTimeoutMs(
                1_000,
                static fn(): array => $connection->select('SELECT * FROM users WHERE id = ?', [1]),
            );
            $plan = $connection->explain('SELECT * FROM users WHERE id = ?', [1]);

            expect($fetched[0]['email'] ?? null)->toBe('ada@example.test')
                ->and($plan)->not->toBeEmpty()
                ->and($connection->getDriverName())->toBe('sqlite')
                ->and($connection->getDatabaseName())->toEndWith('foundation.sqlite');
        });
    } finally {
        DB::purge();
        foundationDatabaseRemove($basePath);
    }
});

it('passes advanced connection configuration through to DBLayer unchanged', function (): void {
    $resolver = new DatabaseConnectionResolver(new ConfigRepository([
        'database' => [
            'default' => 'analytics',
            'connections' => [
                'analytics' => [
                    'driver' => 'pgsql',
                    'host' => 'writer.internal',
                    'port' => 5432,
                    'database' => 'analytics',
                    'username' => 'reporter',
                    'password' => 'secret',
                    'schema' => 'reporting',
                    'prefix' => 'tenant_',
                    'options' => [PDO::ATTR_TIMEOUT => 7],
                    'timeout' => 9,
                    'persistent' => true,
                    'sslmode' => 'verify-full',
                    'write' => ['host' => 'writer.internal'],
                    'read' => [
                        ['host' => 'replica-a.internal', 'weight' => 2],
                        ['host' => 'replica-b.internal', 'weight' => 1],
                    ],
                    'read_strategy' => 'weighted',
                    'read_health_cooldown' => 45,
                    'read_latency_ttl' => 20,
                    'read_probe_sample_size' => 1,
                    'read_session_read_only' => true,
                    'statement_cache_enabled' => true,
                    'statement_cache_size' => 128,
                    'query_comment_enabled' => true,
                    'query_comment_max_length' => 192,
                    'query_comment_context' => ['service' => 'reports'],
                    'sticky' => true,
                    'security' => [
                        'raw_sql_policy' => 'deny',
                        'queries_per_second' => 100,
                    ],
                ],
            ],
        ],
    ]));

    $resolved = $resolver->configuration();
    $normalized = ConnectionConfig::fromArray($resolved);

    expect($resolved['read_strategy'])->toBe('weighted')
        ->and($resolved['options'])->toBe([PDO::ATTR_TIMEOUT => 7])
        ->and($resolved['sslmode'])->toBe('verify-full')
        ->and($normalized->getReadConfigs())->toHaveCount(2)
        ->and($normalized->getReadStrategy())->toBe('weighted')
        ->and($normalized->shouldUseStatementCache())->toBeTrue()
        ->and($normalized->shouldUseQueryComments())->toBeTrue()
        ->and($normalized->securityConfig()['raw_sql_policy'])->toBe('deny');
});

it('publishes all five DBLayer drivers as first class Foundation connections', function (): void {
    /** @var array{connections:array<string,array<string,mixed>>} $configuration */
    $configuration = require dirname(__DIR__, 2) . '/resources/config/database.php';
    $connections = $configuration['connections'];

    expect(array_keys($connections))->toBe(['mysql', 'mariadb', 'pgsql', 'mssql', 'sqlite'])
        ->and(ConnectionConfig::fromArray($connections['mysql'])->getDriver())->toBe('mysql')
        ->and(ConnectionConfig::fromArray($connections['mariadb'])->getDriver())->toBe('mariadb')
        ->and(ConnectionConfig::fromArray($connections['pgsql'])->getDriver())->toBe('pgsql')
        ->and(ConnectionConfig::fromArray($connections['mssql'])->getDriver())->toBe('mssql')
        ->and(ConnectionConfig::fromArray($connections['sqlite'])->getDriver())->toBe('sqlite')
        ->and($connections['mssql'])->toMatchArray([
            'encrypt' => true,
            'trust_server_certificate' => false,
            'application_intent' => 'ReadWrite',
        ])
        ->and($connections['mysql'])->toHaveKey('read_session_read_only')
        ->and($connections['mariadb'])->toHaveKey('read_session_read_only')
        ->and($connections['pgsql'])->toHaveKey('read_session_read_only')
        ->and($connections['mssql'])->not->toHaveKey('read_session_read_only')
        ->and($connections['sqlite']['security'])->not->toHaveKey('require_tls');
});

it('resolves relative sqlite paths for every DBLayer sqlite alias', function (string $driver): void {
    $basePath = sys_get_temp_dir() . '/foundation-db-resolver';
    $resolver = new DatabaseConnectionResolver(new ConfigRepository([
        'app' => ['base_path' => $basePath],
        'database' => [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'driver' => $driver,
                    'database' => 'database/testing.sqlite',
                ],
            ],
        ],
    ]));

    expect($resolver->configuration()['database'])
        ->toBe($basePath . DIRECTORY_SEPARATOR . 'database/testing.sqlite');
})->with(['sqlite', 'sqlite3', 'pdo_sqlite']);

function foundationDatabaseRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $entries = scandir($directory);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            foundationDatabaseRemove($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}
