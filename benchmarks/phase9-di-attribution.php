<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Benchmarks\Support\Phase9DiNode;
use Infocyph\Foundation\Benchmarks\Support\Phase9DiProvider;
use Infocyph\Foundation\Benchmarks\Support\Phase9DiScopedProbe;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;
use Infocyph\InterMix\DI\ContainerBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';
/**
 * @param array{median_ns:float|int, ...} $foundation
 * @param array{median_ns:float|int, ...} $direct
 * @return array{delta_ns:float,percent:float}
 */
function phase9DiTax(array $foundation, array $direct): array
{
    $directMedian = (float) $direct['median_ns'];
    $delta = (float) $foundation['median_ns'] - $directMedian;

    return [
        'delta_ns' => round($delta, 2),
        'percent' => round(($delta / max(0.000001, $directMedian)) * 100.0, 2),
    ];
}

$operations = max(1_000, (int) ((($__foundation154_26 = getenv('PHASE9_DI_OPERATIONS')) !== false && $__foundation154_26 !== '' && $__foundation154_26 !== '0' ? $__foundation154_26 : 100_000)));
$repetitions = max(3, (int) ((($__foundation154_27 = getenv('PHASE9_DI_REPETITIONS')) !== false && $__foundation154_27 !== '' && $__foundation154_27 !== '0' ? $__foundation154_27 : 7)));
$warmup = max(100, (int) ((($__foundation154_28 = getenv('PHASE9_DI_WARMUP')) !== false && $__foundation154_28 !== '' && $__foundation154_28 !== '0' ? $__foundation154_28 : 1_000)));
$root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    . 'foundation-phase9-di-' . bin2hex(random_bytes(6));
mkdir($root . '/bootstrap/cache', 0777, true);
$directArtifact = $root . '/direct-intermix.php';
$foundationArtifact = $root . '/bootstrap/cache/cli.php';

try {
    $directBuilder = ContainerBuilder::create('foundation.phase9.direct');
    $directBuilder->setEnvironment('production');
    Phase9DiProvider::definitions($directBuilder);
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

    $directResolve = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::throughputMeasure(
        static fn(): object => $direct->get(Phase9DiNode::class),
        $operations,
        $repetitions,
        $warmup,
    );
    $foundationContainerResolve = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::throughputMeasure(
        static fn(): object => $foundation->container->get(Phase9DiNode::class),
        $operations,
        $repetitions,
        $warmup,
    );
    $foundationFacadeResolve = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::throughputMeasure(
        static fn(): object => $foundation->application->make(Phase9DiNode::class),
        $operations,
        $repetitions,
        $warmup,
    );

    $scopeOperations = max(1_000, intdiv($operations, 10));
    $directScope = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::throughputMeasure(
        static fn(): object => $direct->withinScope(
            'foundation.cli',
            static fn(): object => $direct->get(Phase9DiScopedProbe::class),
        ),
        $scopeOperations,
        $repetitions,
        max(100, intdiv($warmup, 10)),
    );
    $foundationScope = \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::throughputMeasure(
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
            'foundation_commit' => (($__foundation154_110 = getenv('GITHUB_SHA')) !== false && $__foundation154_110 !== '' && $__foundation154_110 !== '0' ? $__foundation154_110 : 'working-tree'),
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

    $output = (($__foundation157_152 = getenv('PHASE9_DI_OUTPUT')) !== false && $__foundation157_152 !== '' && $__foundation157_152 !== '0') ? $__foundation157_152 : dirname(__DIR__) . '/build/phase9-di-attribution.json';
    $directory = dirname($output);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create benchmark output directory "%s".', $directory));
    }
    file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::removeDirectory($root);
}
