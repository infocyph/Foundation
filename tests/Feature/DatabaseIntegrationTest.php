<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\ConfigRuntime;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Facades\DB;
use Infocyph\Foundation\Foundation;

it('keeps auth schema creation and teardown statements stable', function (): void {
    $tables = new AuthTables();
    $schema = new AuthSchema($tables);
    $statements = $schema->statements();
    $dropStatements = $schema->dropStatements();

    expect($dropStatements)->toHaveCount(count($tables->all()))
        ->and($dropStatements[0])->toBe('DROP TABLE IF EXISTS auth_lockouts')
        ->and($dropStatements[array_key_last($dropStatements)])->toBe('DROP TABLE IF EXISTS auth_accounts')
        ->and($statements)->toContain('CREATE INDEX IF NOT EXISTS auth_grants_resource_idx ON auth_grants (resource_type, resource_id)')
        ->and($statements)->toContain('CREATE INDEX IF NOT EXISTS auth_refresh_tokens_family_idx ON auth_refresh_tokens (family_id)');
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

    expect(array_keys($configuration))->toBe(['default', 'pool', 'connections'])
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
