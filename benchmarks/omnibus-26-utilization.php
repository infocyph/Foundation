<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Foundation\Benchmarks\Support\Omnibus26BenchmarkCodec;
use Infocyph\Foundation\Benchmarks\Support\Omnibus26BenchmarkMessage;
use Infocyph\Foundation\Benchmarks\Support\Omnibus26StopLifecycle;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\MessagingDatabaseSchema;
use Infocyph\Foundation\Messaging\OmnibusWorkerFactory;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\DirectExecutionScope;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerOptions;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Retry\ExponentialRetryStrategy;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodec;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';


/** @return array{median_ns:float,min_ns:float,max_ns:float,spread_percent:float} */
function omnibus26Measure(callable $operation, int $operations, int $repetitions, int $warmup): array
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

function omnibus26Ratio(float $numerator, float $denominator): float
{
    return round($numerator / max(1.0, $denominator), 4);
}

function omnibus26Cycle(MessageBus $bus, InMemoryTransport|DBLayerTransport $transport): void
{
    $bus->dispatch(new Omnibus26BenchmarkMessage('bench'));
    $reservations = [...$transport->receive('benchmark')];
    if (count($reservations) !== 1) {
        throw new RuntimeException('Omnibus benchmark expected exactly one reservation.');
    }
    $transport->acknowledge($reservations[0]);
}

$operations = max(100, (int) (getenv('OMNIBUS_RUNTIME_OPERATIONS') ?: 1_000));
$durableOperations = max(20, (int) (getenv('OMNIBUS_DURABLE_OPERATIONS') ?: 100));
$workerOperations = max(50, (int) (getenv('OMNIBUS_WORKER_OPERATIONS') ?: 500));
$repetitions = max(3, (int) (getenv('OMNIBUS_RUNTIME_REPETITIONS') ?: 5));
$warmup = max(10, (int) (getenv('OMNIBUS_RUNTIME_WARMUP') ?: 50));

$clock = new SystemClock();
$message = Omnibus26BenchmarkMessage::class;
$route = new Route('memory', 'benchmark');
$directMemory = new InMemoryTransport($clock);
$directMemoryBus = new MessageBus(
    new RouteMap([$message => $route]),
    new TransportRegistry(['memory' => $directMemory]),
);

$foundationMemoryApp = Foundation::worker([
    'messaging' => [
        'routes' => [
            $message => ['transport' => 'memory', 'queue' => 'benchmark'],
        ],
        'handlers' => [],
        'workers' => [
            'benchmark' => [
                'transport' => 'memory',
                'queue' => 'benchmark',
                'idle_sleep_seconds' => 0.0,
                'max_idle_sleep_seconds' => 0.0,
                'idle_jitter_ratio' => 0.0,
                'handle_signals' => false,
                'pool' => ['enabled' => false],
            ],
        ],
    ],
])->boot();
$foundationMemoryBus = $foundationMemoryApp->make(MessageBus::class);
$foundationMemory = $foundationMemoryApp->make(InMemoryTransport::class);

$serializer = new JsonEnvelopeSerializer(
    new MessageCodecRegistry([new Omnibus26BenchmarkCodec()]),
    new StampCodecRegistry(CoreStampCodecs::all()),
);
$directDatabasePath = tempnam(sys_get_temp_dir(), 'foundation-omnibus-direct-');
$foundationDatabasePath = tempnam(sys_get_temp_dir(), 'foundation-omnibus-bridge-');
if (!is_string($directDatabasePath) || !is_string($foundationDatabasePath)) {
    throw new RuntimeException('Unable to allocate Omnibus benchmark databases.');
}

$directConnection = new Connection(ConnectionConfig::fromArray([
    'driver' => 'sqlite',
    'database' => $directDatabasePath,
]));
foreach (QueueSchema::statements('sqlite') as $statement) {
    $directConnection->statement($statement);
}
$directDurable = new DBLayerTransport($directConnection, $serializer, $clock);
$directDurableBus = new MessageBus(
    new RouteMap([$message => new Route('database', 'benchmark')]),
    new TransportRegistry(['database' => $directDurable]),
);

$foundationDurableApp = Foundation::worker([
    'database' => [
        'default' => 'main',
        'connections' => [
            'main' => ['driver' => 'sqlite', 'database' => $foundationDatabasePath],
        ],
    ],
    'messaging' => [
        'durable' => [
            'enabled' => true,
            'connection' => 'main',
            'failure_store' => 'database',
        ],
        'serialization' => [
            'message_codecs' => [Omnibus26BenchmarkCodec::class],
        ],
        'routes' => [
            $message => ['transport' => 'database', 'queue' => 'benchmark'],
        ],
        'handlers' => [],
        'workers' => [],
    ],
])->boot();
$foundationDurableApp->make(MessagingDatabaseSchema::class)->install();
$foundationDurableBus = $foundationDurableApp->make(MessageBus::class);
$foundationDurable = $foundationDurableApp->make(DBLayerTransport::class);

$directConsumer = new Consumer(
    $directMemory,
    new HandlerInvoker(new HandlerMap([])),
    new ExponentialRetryStrategy(),
    new InMemoryFailureStore($clock),
    $clock,
    new DirectExecutionScope(),
);
$directWorkerOptions = new WorkerOptions(
    queue: 'benchmark',
    idleSleepSeconds: 0.0,
    maxIdleSleepSeconds: 0.0,
    idleJitterRatio: 0.0,
    handleSignals: false,
);
$stop = new Omnibus26StopLifecycle();
$foundationWorkerFactory = $foundationMemoryApp->make(OmnibusWorkerFactory::class);

try {
    $subjects = [
        'direct_memory_cycle' => omnibus26Measure(
            static fn() => omnibus26Cycle($directMemoryBus, $directMemory),
            $operations,
            $repetitions,
            $warmup,
        ),
        'foundation_memory_cycle' => omnibus26Measure(
            static fn() => omnibus26Cycle($foundationMemoryBus, $foundationMemory),
            $operations,
            $repetitions,
            $warmup,
        ),
        'direct_durable_cycle' => omnibus26Measure(
            static fn() => omnibus26Cycle($directDurableBus, $directDurable),
            $durableOperations,
            $repetitions,
            max(5, intdiv($warmup, 5)),
        ),
        'foundation_durable_cycle' => omnibus26Measure(
            static fn() => omnibus26Cycle($foundationDurableBus, $foundationDurable),
            $durableOperations,
            $repetitions,
            max(5, intdiv($warmup, 5)),
        ),
        'direct_worker_immediate_stop' => omnibus26Measure(
            static function () use ($directConsumer, $directWorkerOptions, $stop): void {
                (new Worker($directConsumer, $directWorkerOptions, $stop))->run();
            },
            $workerOperations,
            $repetitions,
            $warmup,
        ),
        'foundation_worker_factory_immediate_stop' => omnibus26Measure(
            static function () use ($foundationWorkerFactory, $stop): void {
                $foundationWorkerFactory->make('benchmark', $stop)->run();
            },
            $workerOperations,
            $repetitions,
            $warmup,
        ),
    ];

    $report = [
        'schema_version' => 1,
        'generated_at' => gmdate(DATE_ATOM),
        'metadata' => [
            'suite' => 'foundation-omnibus-2.6-utilization',
            'omnibus' => InstalledVersions::getPrettyVersion('infocyph/omnibus') ?? 'unknown',
            'dblayer' => InstalledVersions::getPrettyVersion('infocyph/dblayer') ?? 'unknown',
            'cachelayer' => InstalledVersions::getPrettyVersion('infocyph/cachelayer') ?? 'unknown',
            'boundary' => 'Omnibus owns bus/worker/durable mechanics; Foundation contributes configuration, DI, execution scope and DB/cache selection policy.',
        ],
        'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local-cli',
        'operations_per_repetition' => $operations,
        'durable_operations_per_repetition' => $durableOperations,
        'worker_operations_per_repetition' => $workerOperations,
        'repetitions' => $repetitions,
        'subjects' => $subjects,
        'ratios' => [
            'foundation_memory_vs_direct_omnibus' => omnibus26Ratio(
                $subjects['foundation_memory_cycle']['median_ns'],
                $subjects['direct_memory_cycle']['median_ns'],
            ),
            'foundation_durable_vs_direct_omnibus' => omnibus26Ratio(
                $subjects['foundation_durable_cycle']['median_ns'],
                $subjects['direct_durable_cycle']['median_ns'],
            ),
            'foundation_worker_factory_vs_direct_worker' => omnibus26Ratio(
                $subjects['foundation_worker_factory_immediate_stop']['median_ns'],
                $subjects['direct_worker_immediate_stop']['median_ns'],
            ),
        ],
        'attribution' => [
            'memory' => 'Both paths use native Omnibus MessageBus and InMemoryTransport; the Foundation result measures its configured graph rather than a wrapper dispatcher.',
            'durable' => 'Both paths use native Omnibus DBLayerTransport and safe JSON codec registries; SQLite I/O remains present on both sides.',
            'worker' => 'The direct subject constructs a native Omnibus Worker over a reused Consumer; the Foundation subject adds worker config lookup and ConsumerFactory selection.',
            'pool' => 'Raw native/Runwire process supervision benchmarks remain Omnibus/Runwire-owned; Foundation no longer has a pool watchdog to benchmark independently.',
        ],
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 3),
    ];

    $build = dirname(__DIR__) . '/build';
    if (!is_dir($build) && !mkdir($build, 0777, true) && !is_dir($build)) {
        throw new RuntimeException('Unable to create Omnibus benchmark output directory.');
    }

    $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($build . '/omnibus-2.6-benchmark.json', $encoded . PHP_EOL);
    fwrite(STDOUT, $encoded . PHP_EOL);
} finally {
    $directConnection->disconnect();
    unset($foundationDurableApp, $foundationMemoryApp);
    foreach ([$directDatabasePath, $foundationDatabasePath] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
