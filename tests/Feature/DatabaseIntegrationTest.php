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
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\Foundation\Foundation;

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
    $connection = new Connection(new ConnectionConfig([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $runner = new MigrationRunner($connection, [$schema]);

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
});

it('runs configured migrations seeders and database test transactions', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-migrations-' . bin2hex(random_bytes(6));
    mkdir($basePath . '/database', 0775, true);
    $app = Foundation::cli([
        'base_path' => $basePath,
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
        $database = $app->make(DatabaseManager::class);
        $runner = $database->migrations()->runner();

        expect($runner->run())->toBe(['20260730000000_create_foundation_examples'])
            ->and($database->migrations()->seed())->toBe(1)
            ->and($database->connection()->select('SELECT name FROM foundation_examples ORDER BY id'))
            ->toBe([['name' => 'seeded']]);

        $result = $app->testing()->database()->transaction(
            function () use ($database): string {
                $database->connection()->statement(
                    'INSERT INTO foundation_examples (name) VALUES (?)',
                    ['temporary'],
                );

                return 'completed';
            },
        );

        expect($result)->toBe('completed')
            ->and($database->connection()->select('SELECT name FROM foundation_examples ORDER BY id'))
            ->toBe([['name' => 'seeded']])
            ->and($app->testing()->database()->refresh())
            ->toBe(['20260730000000_create_foundation_examples']);
    } finally {
        DB::purge();
        foundationDatabaseRemove($basePath);
    }
});

it('requires explicit authorization for destructive migration operations', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-migration-commands-' . bin2hex(random_bytes(6));
    mkdir($basePath . '/database', 0775, true);
    $app = Foundation::cli([
        'base_path' => $basePath,
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
        ],
    ]);
    $database = $app->make(DatabaseManager::class);
    $runner = $database->migrations()->runner();
    $schema = new SchemaManager($database->connection());

    try {
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
    } finally {
        DB::purge();
        foundationDatabaseRemove($basePath);
    }
});

it('exposes DBLayer directly while Foundation keeps composition policy', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-db-' . bin2hex(random_bytes(6));
    mkdir($basePath . '/database', 0775, true);

    $app = Foundation::web([
        'app' => ['base_path' => $basePath],
        'database' => [
            'default' => 'main',
            'pool' => [
                'max_connections' => 3,
                'idle_timeout' => 15,
                'max_lifetime' => 300,
                'health_check_interval' => 10,
            ],
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'database' => 'database/foundation.sqlite',
                ],
            ],
        ],
    ]);

    $database = $app->make(DatabaseManager::class);
    $connection = $app->make(Connection::class);
    $events = [];

    try {
        expect($connection)->toBe($database->connection())
            ->and($database->pool()->getConfig())->toMatchArray([
                'max_connections' => 3,
                'idle_timeout' => 15,
                'max_lifetime' => 300,
                'health_check_interval' => 10,
            ]);

        DB::enableQueryLog();
        DB::enableTelemetry();
        DB::setTelemetryBufferLimits(32, 32);
        DB::listen(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $connection->getPdo()->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)',
        );
        $created = DB::repository('users')->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
        ]);
        $fetched = DB::withQueryTimeout(
            1_000,
            static fn(): mixed => DB::repository('users')->find($created['id']),
        );
        $plan = DB::explain('SELECT * FROM users WHERE id = ?', [$created['id']]);

        expect($fetched['email'] ?? null)->toBe('ada@example.test')
            ->and($plan)->not->toBeEmpty()
            ->and(DB::getDriverName())->toBe('sqlite')
            ->and(DB::getDatabaseName())->toEndWith('foundation.sqlite')
            ->and(DB::getQueryLog())->not->toBeEmpty()
            ->and(DB::telemetry()['queries'] ?? [])->not->toBeEmpty()
            ->and($events)->not->toBeEmpty();
    } finally {
        DB::disableTelemetry();
        DB::disableQueryLog();
        DB::flushQueryLog();
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
