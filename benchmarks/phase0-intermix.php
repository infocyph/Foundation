<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;

$autoload = getenv('PHASE0_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
require $autoload;

final readonly class Phase0Leaf
{
    public function __construct(public string $value) {}
}

final readonly class Phase0Node
{
    public function __construct(public Phase0Leaf $leaf) {}
}

/** @return array{median_ns:float,ops_per_second:float,min_ns:float,max_ns:float} */
function benchmark(callable $operation, int $operations, int $repetitions): array
{
    $samples = [];

    for ($repeat = 0; $repeat < $repetitions; ++$repeat) {
        for ($warmup = 0; $warmup < 1_000; ++$warmup) {
            $operation();
        }

        $started = hrtime(true);
        for ($i = 0; $i < $operations; ++$i) {
            $operation();
        }
        $samples[] = (hrtime(true) - $started) / $operations;
    }

    sort($samples, SORT_NUMERIC);
    $median = $samples[intdiv(count($samples), 2)];

    return [
        'median_ns' => round($median, 2),
        'ops_per_second' => round(1_000_000_000 / $median, 2),
        'min_ns' => round($samples[0], 2),
        'max_ns' => round($samples[array_key_last($samples)], 2),
    ];
}

function graph(string $alias): ContainerBuilder
{
    $builder = ContainerBuilder::create($alias);
    $builder->setEnvironment('benchmark');
    $builder->bind(
        Phase0Leaf::class,
        FactoryDefinition::construct(Phase0Leaf::class, ['phase-0']),
        LifetimeEnum::Singleton,
    );
    $builder->bind(
        Phase0Node::class,
        FactoryDefinition::construct(Phase0Node::class, [new ServiceReference(Phase0Leaf::class)]),
        LifetimeEnum::Transient,
    );

    return $builder;
}

$operations = max(10_000, (int) (getenv('PHASE0_OPERATIONS') ?: 200_000));
$repetitions = max(3, (int) (getenv('PHASE0_REPETITIONS') ?: 7));
$artifact = sys_get_temp_dir() . '/foundation-phase0-intermix-' . getmypid() . '.php';

$memoryStart = memory_get_usage(true);
$coldStarted = hrtime(true);
$builder = graph('foundation.phase0.development');
$development = $builder->development();
$development->get(Phase0Node::class);
$developmentColdNs = hrtime(true) - $coldStarted;

$developmentResult = benchmark(
    static fn(): mixed => $development->get(Phase0Node::class),
    $operations,
    $repetitions,
);

$compileStarted = hrtime(true);
$compileBuilder = graph('foundation.phase0.production');
$validation = $compileBuilder->validate(strict: true);
$compileReport = $compileBuilder->compile($artifact);
$compileNs = hrtime(true) - $compileStarted;

$productionLoadStarted = hrtime(true);
$production = $compileBuilder->production($artifact);
$production->get(Phase0Node::class);
$productionLoadNs = hrtime(true) - $productionLoadStarted;

$productionResult = benchmark(
    static fn(): mixed => $production->get(Phase0Node::class),
    $operations,
    $repetitions,
);

$result = [
    'schema' => 1,
    'captured_at_utc' => gmdate(DATE_ATOM),
    'runtime' => [
        'php' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY,
        'opcache_cli' => filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL),
    ],
    'source' => [
        'intermix_version' => InstalledVersions::getPrettyVersion('infocyph/intermix'),
        'intermix_reference' => InstalledVersions::getReference('infocyph/intermix'),
    ],
    'configuration' => [
        'operations' => $operations,
        'repetitions' => $repetitions,
        'warmup_per_repetition' => 1_000,
        'graph' => 'singleton leaf + transient constructor-recipe node',
    ],
    'development' => [
        'cold_build_and_first_resolve_ns' => $developmentColdNs,
        'warm_transient_resolve' => $developmentResult,
    ],
    'generated_production' => [
        'validate_errors' => $validation,
        'compile_ns' => $compileNs,
        'load_and_first_resolve_ns' => $productionLoadNs,
        'compiled_count' => count($compileReport['compiled']),
        'skipped' => $compileReport['skipped'],
        'digest' => $compileReport['digest'],
        'warm_transient_resolve' => $productionResult,
    ],
    'memory' => [
        'baseline_bytes' => $memoryStart,
        'final_bytes' => memory_get_usage(true),
        'peak_bytes' => memory_get_peak_usage(true),
    ],
];

$output = getenv('PHASE0_OUTPUT') ?: dirname(__DIR__) . '/build/phase0-intermix.json';
$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException(sprintf('Unable to create benchmark output directory "%s".', $directory));
}

file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
if (is_file($artifact) && !unlink($artifact)) {
    throw new RuntimeException(sprintf('Unable to remove benchmark artifact "%s".', $artifact));
}

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
