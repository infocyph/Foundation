<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Migration\SeedContext;
use Infocyph\DBLayer\Migration\Seeder;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\ConfigRuntime;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Facades\DB;
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
        $connection->statement(
            'INSERT INTO foundation_examples (name) VALUES (?)',
            ['seeded'],
        );
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

it('runs configured DBLayer migrations, seeders, and database test transactions', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-migrations-' . bin2hex(random_bytes(6));
    mkdir($basePath . '/database', 0775, true);
    $app = Foundation::console([
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
        $database = $app->db();
        $runner = $database->migrations()->runner();
        $readiness = $app->readinessReport();

        expect($readiness['migrations']['pending'])->toBe([
            '20260730000000_create_foundation_examples',
        ])->and($runner->status())->toBe([[
            'id' => '20260730000000_create_foundation_examples',
            'applied' => false,
            'batch' => null,
        ]])->and($runner->run())->toBe(['20260730000000_create_foundation_examples'])
            ->and($app->readinessReport()['migrations']['pending'])->toBe([])
            ->and($database->migrations()->seed())->toBe(1)
            ->and($database->connection()->select(
                'SELECT name FROM foundation_examples ORDER BY id',
            ))->toBe([['name' => 'seeded']]);

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
            ->and($database->connection()->select(
                'SELECT name FROM foundation_examples ORDER BY id',
            ))->toBe([['name' => 'seeded']])
            ->and($app->testing()->database()->refresh())
            ->toBe(['20260730000000_create_foundation_examples'])
            ->and($database->connection()->select(
                'SELECT name FROM foundation_examples ORDER BY id',
            ))->toBe([]);
    } finally {
        $app->db()->purge();
        $databasePath = $basePath . '/database/testing.sqlite';
        if (is_file($databasePath)) {
            unlink($databasePath);
        }
        if (is_dir($basePath . '/database')) {
            rmdir($basePath . '/database');
        }
        if (is_dir($basePath)) {
            rmdir($basePath);
        }
    }
});

it('surfaces DBLayer repositories and query observability through Foundation', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-db-' . uniqid('', true);
    mkdir($basePath . '/database', 0775, true);
    mkdir($basePath . '/storage/cache', 0775, true);

    $events = [];

    Foundation::web([
        'app' => [
            'base_path' => $basePath,
        ],
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

    try {
        expect(DB::freshConnection())->toBeInstanceOf(Connection::class);
        expect(DB::pool()->getConfig())->toMatchArray([
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

        DB::pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');

        $created = DB::repository('users')->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
        ]);

        $fetched = DB::withQueryTimeout(1_000, static fn(): mixed => DB::repository('users')->find($created['id']));

        DB::beginTransaction();
        expect(DB::transactionLevel())->toBe(1);
        DB::rollback();

        DB::disconnect();
        expect(DB::reconnect())->toBeInstanceOf(Connection::class);

        $count = DB::withQueryCancellation(
            static fn(): bool => false,
            static fn(): int => DB::table('users')->count(),
        );
        $plan = DB::explain('SELECT * FROM users WHERE id = ?', [$created['id']]);
        $queryShapes = DB::queryShapeReport();

        $ping = DB::ping();
        $driverName = DB::driverName();
        $databaseName = DB::databaseName();
        $stats = DB::stats();
        $queryLog = DB::queryLog();
        $telemetry = DB::telemetry();
        $flushed = DB::flushTelemetry();
        $telemetryAfterFlush = DB::telemetry();

        expect($created['name'])->toBe('Ada Lovelace')
            ->and($fetched)->toBeArray()
            ->and($fetched['email'] ?? null)->toBe('ada@example.test')
            ->and($count)->toBe(1)
            ->and($plan)->not->toBeEmpty()
            ->and($queryShapes['shapes'] ?? [])->not->toBeEmpty()
            ->and($ping)->toBeTrue()
            ->and($driverName)->toBe('sqlite')
            ->and($databaseName)->toEndWith('foundation.sqlite')
            ->and($stats['driver'])->toBe('sqlite')
            ->and($stats['transaction_level'])->toBe(0)
            ->and($queryLog)->not->toBeEmpty()
            ->and($events)->not->toBeEmpty()
            ->and($telemetry)->toHaveKey('queries')
            ->and($telemetry['queries'])->not->toBeEmpty()
            ->and($flushed['queries'])->not->toBeEmpty()
            ->and($telemetryAfterFlush['queries'])->toBeEmpty();
    } finally {
        DB::disableTelemetry();
        DB::disableQueryLog();
        DB::flushQueryLog();
        DB::purge();
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
        ->and($resolved['timeout'])->toBe(9)
        ->and($resolved['persistent'])->toBeTrue()
        ->and($resolved['sslmode'])->toBe('verify-full')
        ->and($resolved['statement_cache_size'])->toBe(128)
        ->and($resolved['query_comment_context'])->toBe(['service' => 'reports'])
        ->and($normalized->getReadConfigs())->toHaveCount(2)
        ->and($normalized->getReadStrategy())->toBe('weighted')
        ->and($normalized->shouldUseStatementCache())->toBeTrue()
        ->and($normalized->shouldUseQueryComments())->toBeTrue()
        ->and($normalized->securityConfig()['raw_sql_policy'])->toBe('deny');
});

it('publishes the complete DBLayer connection policy for every built-in driver', function (): void {
    require_once dirname(__DIR__, 2) . '/src/Config/config_helpers.php';
    ConfigRuntime::activate(dirname(__DIR__, 2));

    /**
     * @var array{
     *   default:mixed,
     *   pool:array<string,int>,
     *   connections:array<string,array<string,mixed>>
     * } $configuration
     */
    $configuration = require dirname(__DIR__, 2) . '/resources/config/database.php';
    $connections = $configuration['connections'];
    $keys = static function (array $values): array {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    };
    $shared = [
        'driver',
        'database',
        'prefix',
        'options',
        'timeout',
        'persistent',
        'write',
        'read',
        'read_strategy',
        'read_health_cooldown',
        'read_latency_ttl',
        'read_probe_sample_size',
        'statement_cache_enabled',
        'statement_cache_size',
        'query_comment_enabled',
        'query_comment_max_length',
        'query_comment_context',
        'sticky',
        'security',
    ];
    $security = [
        'enabled',
        'max_sql_length',
        'max_params',
        'max_param_bytes',
        'queries_per_second',
        'queries_per_minute',
        'rate_limit_key',
        'strict_identifiers',
        'allow_insecure',
        'raw_sql_policy',
        'raw_sql_allowlist',
        'cursor_signing_key',
    ];

    expect(array_keys($configuration))->toBe(['default', 'migrations', 'seeders', 'pool', 'connections'])
        ->and(array_keys($connections))->toBe(['mysql', 'pgsql', 'sqlite'])
        ->and(array_keys($configuration['pool']))->toBe([
            'max_connections',
            'idle_timeout',
            'max_lifetime',
            'health_check_interval',
        ]);

    expect($keys($connections['mysql']))->toBe($keys(array_fill_keys(array_merge($shared, [
        'host',
        'port',
        'username',
        'password',
        'charset',
        'collation',
        'unix_socket',
        'ssl_ca',
        'ssl_cert',
        'ssl_key',
        'ssl_verify_server_cert',
        'read_session_read_only',
    ]), null)))
        ->and($keys($connections['pgsql']))->toBe($keys(array_fill_keys(array_merge($shared, [
            'host',
            'port',
            'username',
            'password',
            'charset',
            'schema',
            'sslmode',
            'read_session_read_only',
        ]), null)))
        ->and($keys($connections['sqlite']))->toBe($keys(array_fill_keys($shared, null)))
        ->and($keys($connections['mysql']['security']))->toBe(
            $keys(array_fill_keys(array_merge($security, ['require_tls']), null)),
        )
        ->and($keys($connections['pgsql']['security']))->toBe(
            $keys(array_fill_keys(array_merge($security, ['require_tls']), null)),
        )
        ->and($keys($connections['sqlite']['security']))->toBe(
            $keys(array_fill_keys($security, null)),
        );

    foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
        expect(ConnectionConfig::fromArray($connections[$driver])->getDriver())->toBe($driver);
    }
});
