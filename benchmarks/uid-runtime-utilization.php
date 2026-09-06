<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Scheduling\SchedulerRuntime;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\UID\Id;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @return array{
 *     name:string,
 *     type:string,
 *     metadata:array<string,mixed>,
 *     repetitions:int,
 *     warmup_operations:int,
 *     duration_seconds:float,
 *     concurrency:int,
 *     result:array<string,mixed>
 * }
 */
function uidRuntimeMeasure(
    string $name,
    string $type,
    callable $operation,
    int $operations,
    int $repetitions,
    int $warmup,
): array {
    $samples = [];
    $totalNanoseconds = 0;

    for ($repeat = 0; $repeat < $repetitions; ++$repeat) {
        for ($iteration = 0; $iteration < $warmup; ++$iteration) {
            $operation();
        }

        $started = hrtime(true);
        for ($iteration = 0; $iteration < $operations; ++$iteration) {
            $operation();
        }
        $durationNanoseconds = max(1, hrtime(true) - $started);
        $totalNanoseconds += $durationNanoseconds;
        $samples[] = $durationNanoseconds / $operations;
    }

    sort($samples, SORT_NUMERIC);
    $median = $samples[intdiv(count($samples), 2)];
    $minimum = $samples[0];
    $maximum = $samples[array_key_last($samples)];
    $spread = (($maximum - $minimum) / max(0.000001, $median)) * 100;
    $attempted = $operations * $repetitions;

    return [
        'name' => $name,
        'type' => $type,
        'metadata' => [
            'operation' => $name,
            'operations_per_repetition' => $operations,
            'median_ns' => round($median, 2),
            'minimum_ns' => round($minimum, 2),
            'maximum_ns' => round($maximum, 2),
        ],
        'repetitions' => $repetitions,
        'warmup_operations' => $warmup,
        'duration_seconds' => $totalNanoseconds / 1_000_000_000,
        'concurrency' => 1,
        'result' => [
            'attempted_operations' => $attempted,
            'successful_operations' => $attempted,
            'failed_operations' => 0,
            'timeouts' => 0,
            'successful_rpm' => 60_000_000_000 / max(0.000001, $median),
            'error_rate' => 0.0,
            'latency_ms' => [
                'minimum' => $minimum / 1_000_000,
                'average' => $median / 1_000_000,
                'p50' => $median / 1_000_000,
                'p95' => uidRuntimePercentile($samples, 0.95) / 1_000_000,
                'p99' => uidRuntimePercentile($samples, 0.99) / 1_000_000,
                'maximum' => $maximum / 1_000_000,
            ],
            'cpu' => [
                'average_percent' => null,
                'peak_percent' => null,
            ],
            'memory' => [
                'average_mb' => null,
                'peak_mb' => memory_get_peak_usage(true) / 1_048_576,
                'growth_mb' => null,
            ],
            'stability' => [
                'status' => 'unverified',
                'spread_percent' => $spread,
            ],
        ],
    ];
}

/** @param list<float> $samples */
function uidRuntimePercentile(array $samples, float $percentile): float
{
    $index = (int) ceil($percentile * count($samples)) - 1;

    return $samples[max(0, min(array_key_last($samples), $index))];
}

/** @return array<string,mixed> */
function uidRuntimeEnvironment(string $uidVersion): array
{
    $extensions = get_loaded_extensions();
    sort($extensions, SORT_STRING);
    $cpuModel = 'unknown';
    if (is_readable('/proc/cpuinfo')) {
        $cpuInfo = file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo) && preg_match('/^model name\s*:\s*(.+)$/m', $cpuInfo, $matches) === 1) {
            $cpuModel = trim($matches[1]);
        }
    }

    $runtime = [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'operating_system' => php_uname(),
        'cpu_model' => $cpuModel,
        'memory_limit' => ini_get('memory_limit') ?: 'unknown',
        'opcache' => extension_loaded('Zend OPcache'),
        'jit' => ini_get('opcache.jit') ?: false,
        'xdebug' => extension_loaded('xdebug'),
        'extensions' => $extensions,
        'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local-cli',
        'release' => $uidVersion,
    ];

    return [
        'stable' => false,
        'fingerprint' => hash('sha256', json_encode($runtime, JSON_THROW_ON_ERROR)),
        ...$runtime,
    ];
}

function uidRuntimeRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($directory);
}

$operations = max(1_000, (int) (getenv('UID_RUNTIME_OPERATIONS') ?: 25_000));
$repetitions = max(3, (int) (getenv('UID_RUNTIME_REPETITIONS') ?: 7));
$warmup = max(100, (int) (getenv('UID_RUNTIME_WARMUP') ?: 500));
$scopeOperations = max(1_000, intdiv($operations, 5));
$scopeWarmup = max(100, intdiv($warmup, 5));
$root = sys_get_temp_dir() . '/foundation-uid-runtime-benchmark-' . bin2hex(random_bytes(6));

if (!mkdir($root, 0777, true) && !is_dir($root)) {
    throw new RuntimeException(sprintf('Unable to create benchmark directory "%s".', $root));
}

try {
    $config = [
        'base_path' => $root,
        '_config_cache' => false,
        'app' => [
            'base_path' => $root,
            'env' => 'testing',
        ],
    ];

    $worker = Foundation::worker($config)->boot();
    $workerRuntime = new WorkerRuntime($worker);
    $scheduler = Foundation::scheduler($config)->boot();
    $schedulerRuntime = new SchedulerRuntime($scheduler);
    $supplied = new ExecutionId('benchmark:upstream-correlation');

    $workloads = [
        uidRuntimeMeasure(
            'raw-uid-uuid7',
            'component',
            static fn(): string => Id::uuid7(),
            $operations,
            $repetitions,
            $warmup,
        ),
        uidRuntimeMeasure(
            'foundation-execution-id-generate',
            'component',
            static fn(): ExecutionId => ExecutionId::generate(),
            $operations,
            $repetitions,
            $warmup,
        ),
        uidRuntimeMeasure(
            'worker-supplied-execution-id',
            'persistent-worker',
            static fn(): string => $workerRuntime->execute(
                static fn(ExecutionId $id): string => $id->value,
                executionId: $supplied,
            ),
            $scopeOperations,
            $repetitions,
            $scopeWarmup,
        ),
        uidRuntimeMeasure(
            'worker-generated-uuid7-fallback',
            'persistent-worker',
            static fn(): string => $workerRuntime->execute(
                static fn(ExecutionId $id): string => $id->value,
            ),
            $scopeOperations,
            $repetitions,
            $scopeWarmup,
        ),
        uidRuntimeMeasure(
            'scheduler-supplied-execution-id',
            'custom',
            static fn(): string => $schedulerRuntime->execute(
                static fn(ExecutionId $id): string => $id->value,
                executionId: $supplied,
            ),
            $scopeOperations,
            $repetitions,
            $scopeWarmup,
        ),
        uidRuntimeMeasure(
            'scheduler-generated-uuid7-fallback',
            'custom',
            static fn(): string => $schedulerRuntime->execute(
                static fn(ExecutionId $id): string => $id->value,
            ),
            $scopeOperations,
            $repetitions,
            $scopeWarmup,
        ),
        uidRuntimeMeasure(
            'nested-execution-id-reuse',
            'persistent-worker',
            static fn(): string => $workerRuntime->execute(
                static fn(ExecutionId $outer) use ($worker): string => $worker->execution()->run(
                    static fn(ExecutionId $inner): string => $inner->value,
                    executionId: $outer,
                ),
                executionId: $supplied,
            ),
            $scopeOperations,
            $repetitions,
            $scopeWarmup,
        ),
    ];

    $uidVersion = InstalledVersions::getPrettyVersion('infocyph/uid') ?? 'unknown';
    $result = [
        'schema_version' => 1,
        'generated_at' => gmdate(DATE_ATOM),
        'environment' => uidRuntimeEnvironment($uidVersion),
        'metadata' => [
            'suite' => 'foundation-uid-runtime-utilization',
            'uid' => $uidVersion,
            'uid_reference' => InstalledVersions::getReference('infocyph/uid'),
            'foundation_commit' => getenv('GITHUB_SHA') ?: 'working-tree',
            'boundary' => 'UID generic generation with Foundation execution-correlation lifecycle',
            'algorithm_decision' => 'Keep UUIDv7. NanoID/ObjectID are not equivalent correlation formats and are therefore not benchmark candidates for this boundary.',
        ],
        'workloads' => $workloads,
    ];

    $buildDirectory = dirname(__DIR__) . '/build';
    if (!is_dir($buildDirectory) && !mkdir($buildDirectory, 0777, true) && !is_dir($buildDirectory)) {
        throw new RuntimeException('Unable to create benchmark output directory.');
    }

    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($buildDirectory . '/uid-5-runtime-benchmark.json', $encoded . PHP_EOL, LOCK_EX);
    fwrite(STDOUT, $encoded . PHP_EOL);
} finally {
    uidRuntimeRemove($root);
}
