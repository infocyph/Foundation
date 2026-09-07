<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "DBLayer 5.1 utilization benchmark requires pdo_sqlite.\n");
    exit(2);
}

/** @return array{median_ns:float,min_ns:float,max_ns:float,spread_percent:float} */
function dblayer51Measure(callable $operation, int $operations, int $repetitions, int $warmup): array
{
    $samples = [];

    for ($repeat = 0; $repeat < $repetitions; ++$repeat) {
        for ($iteration = 0; $iteration < $warmup; ++$iteration) {
            $operation();
        }

        $started = hrtime(true);
        for ($iteration = 0; $iteration < $operations; ++$iteration) {
            $operation();
        }
        $samples[] = max(1, hrtime(true) - $started) / $operations;
    }

    sort($samples, SORT_NUMERIC);
    $median = $samples[intdiv(count($samples), 2)];
    $minimum = $samples[0];
    $maximum = $samples[count($samples) - 1];

    return [
        'median_ns' => round($median, 2),
        'min_ns' => round($minimum, 2),
        'max_ns' => round($maximum, 2),
        'spread_percent' => round((($maximum - $minimum) / max(1.0, $median)) * 100, 2),
    ];
}

function dblayer51Ratio(float $numerator, float $denominator): float
{
    return round($numerator / max(1.0, $denominator), 4);
}

$operations = max(100, (int) (getenv('DBLAYER_RUNTIME_OPERATIONS') ?: 1_000));
$repetitions = max(3, (int) (getenv('DBLAYER_RUNTIME_REPETITIONS') ?: 7));
$warmup = max(20, (int) (getenv('DBLAYER_RUNTIME_WARMUP') ?: 100));

$config = ConnectionConfig::fromArray([
    'driver' => 'sqlite',
    'database' => ':memory:',
    'statement_cache_enabled' => true,
    'statement_cache_size' => 64,
]);

$pool = new Pool([
    'min_connections' => 1,
    'max_connections' => 4,
    'idle_timeout' => 0,
    'max_lifetime' => 0,
    'health_check_interval' => 0,
]);
$pool->addConfig('default', $config);
$manager = new PoolManager($pool);

$warmLease = $manager->checkout('default');
$warmLease->connection()->scalar('select ? as value', [42]);
$warmLease->release();

$dedicated = new Connection($config, 'warm-dedicated');
$dedicated->scalar('select ? as value', [42]);

$subjects = [];
$subjects['construct_connection'] = dblayer51Measure(
    static fn() => new Connection($config, 'construct'),
    $operations,
    $repetitions,
    $warmup,
);
$subjects['open_select_disconnect'] = dblayer51Measure(
    static function () use ($config): void {
        $connection = new Connection($config, 'new');
        $connection->scalar('select 1');
        $connection->disconnect();
    },
    $operations,
    $repetitions,
    $warmup,
);
$subjects['pool_checkout_select_release'] = dblayer51Measure(
    static function () use ($manager): void {
        $lease = $manager->checkout('default');
        $lease->connection()->scalar('select 1');
        $lease->release();
    },
    $operations,
    $repetitions,
    $warmup,
);
$subjects['warm_dedicated_prepared_select'] = dblayer51Measure(
    static fn() => $dedicated->scalar('select ? as value', [42]),
    $operations,
    $repetitions,
    $warmup,
);
$subjects['pool_prepared_select_release'] = dblayer51Measure(
    static function () use ($manager): void {
        $lease = $manager->checkout('default');
        $lease->connection()->scalar('select ? as value', [42]);
        $lease->release();
    },
    $operations,
    $repetitions,
    $warmup,
);
$subjects['foundation_execution_dedicated'] = dblayer51Measure(
    static function () use ($config): void {
        $state = new RuntimeExecutionState();
        $state->connection('default', $config)->scalar('select 1');
        $state->cleanup();
    },
    $operations,
    $repetitions,
    $warmup,
);
$subjects['foundation_execution_leased'] = dblayer51Measure(
    static function () use ($manager): void {
        $state = new RuntimeExecutionState();
        $state->leasedConnection('default', $manager)->scalar('select 1');
        $state->cleanup();
    },
    $operations,
    $repetitions,
    $warmup,
);

$dedicatedNs = (float) $subjects['foundation_execution_dedicated']['median_ns'];
$leasedNs = (float) $subjects['foundation_execution_leased']['median_ns'];
$openNs = (float) $subjects['open_select_disconnect']['median_ns'];
$poolNs = (float) $subjects['pool_checkout_select_release']['median_ns'];

$report = [
    'benchmark' => 'foundation-dblayer-5.1-utilization',
    'versions' => [
        'php' => PHP_VERSION,
        'foundation' => InstalledVersions::getPrettyVersion('infocyph/foundation'),
        'dblayer' => InstalledVersions::getPrettyVersion('infocyph/dblayer'),
        'cachelayer' => InstalledVersions::getPrettyVersion('infocyph/cachelayer'),
    ],
    'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local-cli',
    'operations_per_repetition' => $operations,
    'repetitions' => $repetitions,
    'warmup_operations' => $warmup,
    'subjects' => $subjects,
    'ratios' => [
        'pool_vs_open_select_disconnect' => dblayer51Ratio($poolNs, $openNs),
        'foundation_leased_vs_dedicated' => dblayer51Ratio($leasedNs, $dedicatedNs),
        'foundation_lease_overhead_vs_raw_pool' => dblayer51Ratio($leasedNs, $poolNs),
    ],
    'decision_input' => [
        'pool_default_enabled' => false,
        'material_speedup_threshold_percent' => 15,
        'sqlite_lifecycle_speedup_percent' => round((1 - ($leasedNs / max(1.0, $dedicatedNs))) * 100, 2),
        'note' => 'Pooling remains opt-in until persistent-runtime correctness and representative datastore benchmarks are accepted; SQLite is an attribution baseline, not a network-database proxy.',
    ],
    'pool_stats' => $pool->getStats(),
    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 3),
];

$dedicated->disconnect();
$pool->closeAll();

echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
