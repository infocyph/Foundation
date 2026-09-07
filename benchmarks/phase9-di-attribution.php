<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class Phase9DiLeaf
{
    public function __construct(public string $value) {}
}

final readonly class Phase9DiNode
{
    public function __construct(public Phase9DiLeaf $leaf) {}
}

final class Phase9DiScopedProbe
{
    public int $touches = 0;
}

final class Phase9DiProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);

        phase9DiDefinitions($builder);
    }
}

function phase9DiDefinitions(ContainerBuilder $builder): void
{
    $builder->bind(
        Phase9DiLeaf::class,
        FactoryDefinition::construct(Phase9DiLeaf::class, ['phase-9']),
        LifetimeEnum::Singleton,
    );
    $builder->bind(
        Phase9DiNode::class,
        FactoryDefinition::construct(Phase9DiNode::class, [new ServiceReference(Phase9DiLeaf::class)]),
        LifetimeEnum::Transient,
    );
    $builder->bind(
        Phase9DiScopedProbe::class,
        FactoryDefinition::construct(Phase9DiScopedProbe::class),
        LifetimeEnum::Scoped,
    );
}

/** @return array{median_ns:float,ops_per_second:float,min_ns:float,max_ns:float,samples_ns:list<float>} */
function phase9DiMeasure(callable $operation, int $operations, int $repetitions, int $warmup): array
{
    $samples = [];

    for ($repeat = 0; $repeat < $repetitions; ++$repeat) {
        for ($i = 0; $i < $warmup; ++$i) {
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
        'samples_ns' => array_map(static fn(float $sample): float => round($sample, 2), $samples),
    ];
}

/** @return array{delta_ns:float,percent:float} */
function phase9DiTax(array $foundation, array $direct): array
{
    $delta = $foundation['median_ns'] - $direct['median_ns'];

    return [
        'delta_ns' => round($delta, 2),
        'percent' => round(($delta / max(0.000001, $direct['median_ns'])) * 100, 2),
    ];
}

function phase9DiRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

$operations = max(1_000, (int) (getenv('PHASE9_DI_OPERATIONS') ?: 100_000));
$repetitions = max(3, (int) (getenv('PHASE9_DI_REPETITIONS') ?: 7));
$warmup = max(100, (int) (getenv('PHASE9_DI_WARMUP') ?: 1_000));
$root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    . 'foundation-phase9-di-' . bin2hex(random_bytes(6));
mkdir($root . '/bootstrap/cache', 0777, true);
$directArtifact = $root . '/direct-intermix.php';
$foundationArtifact = $root . '/bootstrap/cache/cli.php';

try {
    $directBuilder = ContainerBuilder::create('foundation.phase9.direct');
    $directBuilder->setEnvironment('production');
    phase9DiDefinitions($directBuilder);
    $directValidation = $directBuilder->validate(strict: true);
    $directCompile = $directBuilder->compile($directArtifact);
    $direct = $directBuilder->production($directArtifact);

    $config = [
        'app' => [
            'base_path' => $root,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'providers' => [
            'common' => [Phase9DiProvider::class],
        ],
    ];
    $compiler = new GeneratedRuntimeCompiler();
    $foundationCompile = $compiler->compile($config, RuntimeMode::Cli, $foundationArtifact);
    $foundation = GeneratedRuntime::load($config, RuntimeMode::Cli, $foundationArtifact);

    if ($directValidation !== [] || $directCompile['skipped'] !== [] || $foundationCompile['skipped'] !== []) {
        throw new RuntimeException('Phase 9 DI attribution requires fully statically compiled comparison graphs.');
    }

    $directResolve = phase9DiMeasure(
        static fn(): object => $direct->get(Phase9DiNode::class),
        $operations,
        $repetitions,
        $warmup,
    );
    $foundationContainerResolve = phase9DiMeasure(
        static fn(): object => $foundation->container->get(Phase9DiNode::class),
        $operations,
        $repetitions,
        $warmup,
    );
    $foundationFacadeResolve = phase9DiMeasure(
        static fn(): object => $foundation->application->make(Phase9DiNode::class),
        $operations,
        $repetitions,
        $warmup,
    );

    $scopeOperations = max(1_000, intdiv($operations, 10));
    $directScope = phase9DiMeasure(
        static fn(): object => $direct->withinScope(
            'foundation.cli',
            static fn(): object => $direct->get(Phase9DiScopedProbe::class),
        ),
        $scopeOperations,
        $repetitions,
        max(100, intdiv($warmup, 10)),
    );
    $foundationScope = phase9DiMeasure(
        static fn(): object => $foundation->application->execution()->run(
            static fn(): object => $foundation->application->make(Phase9DiScopedProbe::class),
        ),
        $scopeOperations,
        $repetitions,
        max(100, intdiv($warmup, 10)),
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
            'foundation_commit' => getenv('GITHUB_SHA') ?: 'working-tree',
            'intermix_version' => InstalledVersions::getPrettyVersion('infocyph/intermix'),
            'intermix_reference' => InstalledVersions::getReference('infocyph/intermix'),
        ],
        'configuration' => [
            'operations' => $operations,
            'scope_operations' => $scopeOperations,
            'repetitions' => $repetitions,
            'warmup' => $warmup,
            'graph' => 'singleton leaf + transient constructor node + scoped probe',
        ],
        'compile' => [
            'direct_intermix' => [
                'compiled_count' => count($directCompile['compiled']),
                'skipped' => $directCompile['skipped'],
                'digest' => $directCompile['digest'],
            ],
            'foundation_cli' => [
                'compiled_count' => count($foundationCompile['compiled']),
                'skipped' => $foundationCompile['skipped'],
                'digest' => $foundationCompile['digest'],
            ],
        ],
        'resolution' => [
            'direct_intermix' => $directResolve,
            'foundation_container' => $foundationContainerResolve,
            'foundation_application_facade' => $foundationFacadeResolve,
            'foundation_container_tax' => phase9DiTax($foundationContainerResolve, $directResolve),
            'foundation_facade_tax' => phase9DiTax($foundationFacadeResolve, $directResolve),
            'application_facade_increment' => phase9DiTax($foundationFacadeResolve, $foundationContainerResolve),
        ],
        'scope' => [
            'direct_intermix_within_scope' => $directScope,
            'foundation_execution_boundary' => $foundationScope,
            'foundation_scope_tax' => phase9DiTax($foundationScope, $directScope),
        ],
        'memory' => [
            'final_bytes' => memory_get_usage(true),
            'peak_bytes' => memory_get_peak_usage(true),
        ],
    ];

    $output = getenv('PHASE9_DI_OUTPUT') ?: dirname(__DIR__) . '/build/phase9-di-attribution.json';
    $directory = dirname($output);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create benchmark output directory "%s".', $directory));
    }
    file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} finally {
    phase9DiRemove($root);
}
