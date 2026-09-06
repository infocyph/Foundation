<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Scheduling\SchedulerRuntime;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\UID\Id;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array{median_ns:float,ops_per_second:float,min_ns:float,max_ns:float,samples_ns:list<float>} */
function uidRuntimeMeasure(callable $operation, int $operations, int $repetitions, int $warmup): array
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
        $samples[] = (hrtime(true) - $started) / $operations;
    }

    sort($samples, SORT_NUMERIC);
    $median = $samples[intdiv(count($samples), 2)];

    return [
        'median_ns' => round($median, 2),
        'ops_per_second' => round(1_000_000_000 / max(0.000001, $median), 2),
        'min_ns' => round($samples[0], 2),
        'max_ns' => round($samples[array_key_last($samples)], 2),
        'samples_ns' => array_map(static fn(float $sample): float => round($sample, 2), $samples),
    ];
}

/** @return array{delta_ns:float,percent:float} */
function uidRuntimeTax(array $withGeneration, array $supplied): array
{
    $delta = $withGeneration['median_ns'] - $supplied['median_ns'];

    return [
        'delta_ns' => round($delta, 2),
        'percent' => round(($delta / max(0.000001, $supplied['median_ns'])) * 100, 2),
    ];
}

$operations = max(1_000, (int) (getenv('UID_RUNTIME_OPERATIONS') ?: 25_000));
$repetitions = max(3, (int) (getenv('UID_RUNTIME_REPETITIONS') ?: 7));
$warmup = max(100, (int) (getenv('UID_RUNTIME_WARMUP') ?: 500));
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

    $rawUuid7 = uidRuntimeMeasure(
        static fn(): string => Id::uuid7(),
        $operations,
        $repetitions,
        $warmup,
    );
    $executionIdGeneration = uidRuntimeMeasure(
        static fn(): ExecutionId => ExecutionId::generate(),
        $operations,
        $repetitions,
        $warmup,
    );

    $scopeOperations = max(1_000, intdiv($operations, 5));
    $scopeWarmup = max(100, intdiv($warmup, 5));
    $workerSupplied = uidRuntimeMeasure(
        static fn(): string => $workerRuntime->execute(
            static fn(ExecutionId $id): string => $id->value,
            executionId: $supplied,
        ),
        $scopeOperations,
        $repetitions,
        $scopeWarmup,
    );
    $workerGenerated = uidRuntimeMeasure(
        static fn(): string => $workerRuntime->execute(
            static fn(ExecutionId $id): string => $id->value,
        ),
        $scopeOperations,
        $repetitions,
        $scopeWarmup,
    );
    $schedulerSupplied = uidRuntimeMeasure(
        static fn(): string => $schedulerRuntime->execute(
            static fn(ExecutionId $id): string => $id->value,
            executionId: $supplied,
        ),
        $scopeOperations,
        $repetitions,
        $scopeWarmup,
    );
    $schedulerGenerated = uidRuntimeMeasure(
        static fn(): string => $schedulerRuntime->execute(
            static fn(ExecutionId $id): string => $id->value,
        ),
        $scopeOperations,
        $repetitions,
        $scopeWarmup,
    );
    $nestedReuse = uidRuntimeMeasure(
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
    );

    $result = [
        'schema_version' => 1,
        'generated_at' => gmdate(DATE_ATOM),
        'environment' => [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'operating_system' => php_uname(),
            'memory_limit' => ini_get('memory_limit') ?: 'unknown',
            'opcache_cli' => filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL),
            'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local-cli',
        ],
        'source' => [
            'foundation_commit' => getenv('GITHUB_SHA') ?: 'working-tree',
            'uid_version' => InstalledVersions::getPrettyVersion('infocyph/uid'),
            'uid_reference' => InstalledVersions::getReference('infocyph/uid'),
        ],
        'configuration' => [
            'generator_operations' => $operations,
            'scope_operations' => $scopeOperations,
            'repetitions' => $repetitions,
            'warmup' => $warmup,
        ],
        'generator' => [
            'raw_uid_uuid7' => $rawUuid7,
            'foundation_execution_id' => $executionIdGeneration,
            'foundation_wrapper_tax' => uidRuntimeTax($executionIdGeneration, $rawUuid7),
        ],
        'worker' => [
            'supplied_execution_id' => $workerSupplied,
            'generated_uuid7_fallback' => $workerGenerated,
            'generation_increment' => uidRuntimeTax($workerGenerated, $workerSupplied),
        ],
        'scheduler' => [
            'supplied_execution_id' => $schedulerSupplied,
            'generated_uuid7_fallback' => $schedulerGenerated,
            'generation_increment' => uidRuntimeTax($schedulerGenerated, $schedulerSupplied),
        ],
        'nested_reuse' => $nestedReuse,
        'algorithm_decision' => [
            'default' => 'uuid7',
            'candidate_benchmarks_skipped' => ['nanoid', 'objectid'],
            'reason' => 'Compact alternatives change Foundation correlation representation semantics; no equivalent boundary requires them.',
        ],
        'memory' => [
            'final_bytes' => memory_get_usage(true),
            'peak_bytes' => memory_get_peak_usage(true),
        ],
    ];

    $buildDirectory = dirname(__DIR__) . '/build';
    if (!is_dir($buildDirectory) && !mkdir($buildDirectory, 0777, true) && !is_dir($buildDirectory)) {
        throw new RuntimeException('Unable to create benchmark output directory.');
    }

    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($buildDirectory . '/uid-5-runtime-benchmark.json', $encoded . PHP_EOL, LOCK_EX);
    fwrite(STDOUT, $encoded . PHP_EOL);
} finally {
    if (is_dir($root)) {
        rmdir($root);
    }
}
