<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\EnvironmentLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/foundation-arraykit-benchmark-' . bin2hex(random_bytes(6));
$configDirectory = $root . '/config';
$compiledDirectory = $root . '/compiled';
$environmentNone = $root . '/env-none';
$environmentOne = $root . '/env-one';
$environmentPair = $root . '/env-pair';
$environmentKey = 'FOUNDATION_ARRAYKIT_BENCHMARK';
$environmentSnapshot = arrayKitBenchmarkEnvironmentSnapshot($environmentKey);

foreach ([$configDirectory, $compiledDirectory, $environmentNone, $environmentOne, $environmentPair] as $directory) {
    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create benchmark directory "%s".', $directory));
    }
}

file_put_contents($configDirectory . '/app.php', <<<'PHP'
<?php

return [
    'name' => 'benchmark',
    'deep' => [
        'value' => 'source',
        'nested' => ['enabled' => true],
    ],
    'servers' => ['one', 'two', 'three'],
];
PHP);
file_put_contents($configDirectory . '/cache.php', <<<'PHP'
<?php

return [
    'default' => 'local',
    'stores' => [
        'local' => ['driver' => 'array'],
        'shared' => ['driver' => 'redis'],
    ],
];
PHP);
file_put_contents($environmentOne . '/.env', $environmentKey . "=one\n");
file_put_contents($environmentPair . '/.env', $environmentKey . "=base\n");
file_put_contents($environmentPair . '/.env.local', $environmentKey . "=local\n");

try {
    $loader = new ConfigLoader();
    $sourceInput = [
        'base_path' => $root,
        '_config_cache' => false,
    ];
    $compiledData = $loader->load($sourceInput)->all();
    $compiledFile = $compiledDirectory . '/config.php';
    file_put_contents($compiledFile, "<?php\n\nreturn " . var_export($compiledData, true) . ";\n");

    $warmLazy = ConfigRepository::fromLazyFiles(
        directory: $configDirectory,
        cacheDirectory: null,
        fallback: [],
        overrides: [],
        namespaces: ['app', 'cache'],
    );
    $warmLazy->get('app.deep.value');

    $warmRepository = new ConfigRepository($compiledData, compiled: true);
    $warmRepository->get('app.deep.value');

    $workloads = [
        arrayKitBenchmarkMeasure('source-config-composition', 250, static function () use ($sourceInput): void {
            $config = (new ConfigLoader())->load($sourceInput)->all();
            if (($config['app']['name'] ?? null) !== 'benchmark') {
                throw new RuntimeException('Unexpected source configuration result.');
            }
        }),
        arrayKitBenchmarkMeasure('first-lazy-namespace-lookup', 1_000, static function () use ($configDirectory): void {
            $config = ConfigRepository::fromLazyFiles(
                directory: $configDirectory,
                cacheDirectory: null,
                fallback: [],
                overrides: [],
                namespaces: ['app', 'cache'],
            );
            if ($config->get('app.deep.value') !== 'source') {
                throw new RuntimeException('Unexpected first lazy lookup result.');
            }
        }),
        arrayKitBenchmarkMeasure('warm-lazy-namespace-lookup', 25_000, static function () use ($warmLazy): void {
            if ($warmLazy->get('app.deep.value') !== 'source') {
                throw new RuntimeException('Unexpected warm lazy lookup result.');
            }
        }),
        arrayKitBenchmarkMeasure('full-config-materialization', 750, static function () use ($configDirectory): void {
            $config = ConfigRepository::fromLazyFiles(
                directory: $configDirectory,
                cacheDirectory: null,
                fallback: [],
                overrides: [],
                namespaces: ['app', 'cache'],
            )->all();
            if (($config['cache']['default'] ?? null) !== 'local') {
                throw new RuntimeException('Unexpected materialized configuration result.');
            }
        }),
        arrayKitBenchmarkMeasure('trusted-compiled-config-load', 5_000, static function () use ($compiledFile): void {
            $config = require $compiledFile;
            if (!is_array($config) || ($config['app']['name'] ?? null) !== 'benchmark') {
                throw new RuntimeException('Unexpected compiled configuration result.');
            }
        }),
        arrayKitBenchmarkMeasure('warm-config-repository-dot-get', 50_000, static function () use ($warmRepository): void {
            if ($warmRepository->get('app.deep.value') !== 'source') {
                throw new RuntimeException('Unexpected warm repository lookup result.');
            }
        }),
        arrayKitBenchmarkMeasure('env-bootstrap-none', 1_000, static function () use ($environmentNone): void {
            new EnvironmentLoader()->load($environmentNone);
        }),
        arrayKitBenchmarkMeasure('env-bootstrap-one-file', 500, static function () use ($environmentKey, $environmentOne): void {
            arrayKitBenchmarkEnvironmentUnset($environmentKey);
            new EnvironmentLoader()->load($environmentOne);
            if (($_ENV[$environmentKey] ?? null) !== 'one') {
                throw new RuntimeException('Unexpected one-file environment result.');
            }
        }),
        arrayKitBenchmarkMeasure('env-bootstrap-env-plus-local', 500, static function () use ($environmentKey, $environmentPair): void {
            arrayKitBenchmarkEnvironmentUnset($environmentKey);
            new EnvironmentLoader()->load($environmentPair);
            if (($_ENV[$environmentKey] ?? null) !== 'local') {
                throw new RuntimeException('Unexpected two-file environment result.');
            }
        }),
    ];

    $result = [
        'schema_version' => 1,
        'generated_at' => gmdate(DATE_ATOM),
        'metadata' => [
            'suite' => 'foundation-arraykit-config-utilization',
            'arraykit' => InstalledVersions::getPrettyVersion('infocyph/arraykit') ?? 'unknown',
            'php' => PHP_VERSION,
            'boundary' => 'ArrayKit generic config/env primitives with Foundation application policy',
        ],
        'workloads' => $workloads,
    ];

    $buildDirectory = dirname(__DIR__) . '/build';
    if (!is_dir($buildDirectory) && !mkdir($buildDirectory, 0777, true) && !is_dir($buildDirectory)) {
        throw new RuntimeException('Unable to create benchmark output directory.');
    }

    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($buildDirectory . '/arraykit-5.2-benchmark.json', $encoded . PHP_EOL);
    echo $encoded . PHP_EOL;

    foreach ($workloads as $workload) {
        if ($workload['failed_operations'] > 0) {
            exit(1);
        }
    }
} finally {
    arrayKitBenchmarkEnvironmentRestore($environmentKey, $environmentSnapshot);
    arrayKitBenchmarkRemoveDirectory($root);
}

/**
 * @return array{name:string,iterations:int,duration_ns:int,ops_per_second:float,successful_operations:int,failed_operations:int}
 */
function arrayKitBenchmarkMeasure(string $name, int $iterations, Closure $operation): array
{
    $successful = 0;
    $failed = 0;
    $started = hrtime(true);

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        try {
            $operation();
            $successful++;
        } catch (Throwable) {
            $failed++;
        }
    }

    $duration = max(1, hrtime(true) - $started);

    return [
        'name' => $name,
        'iterations' => $iterations,
        'duration_ns' => $duration,
        'ops_per_second' => $successful * 1_000_000_000 / $duration,
        'successful_operations' => $successful,
        'failed_operations' => $failed,
    ];
}

/** @return array{env_exists:bool,env:mixed,server_exists:bool,server:mixed,process:string|false} */
function arrayKitBenchmarkEnvironmentSnapshot(string $key): array
{
    return [
        'env_exists' => array_key_exists($key, $_ENV),
        'env' => $_ENV[$key] ?? null,
        'server_exists' => array_key_exists($key, $_SERVER),
        'server' => $_SERVER[$key] ?? null,
        'process' => getenv($key),
    ];
}

function arrayKitBenchmarkEnvironmentUnset(string $key): void
{
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
}

/** @param array{env_exists:bool,env:mixed,server_exists:bool,server:mixed,process:string|false} $snapshot */
function arrayKitBenchmarkEnvironmentRestore(string $key, array $snapshot): void
{
    if ($snapshot['env_exists']) {
        $_ENV[$key] = $snapshot['env'];
    } else {
        unset($_ENV[$key]);
    }

    if ($snapshot['server_exists']) {
        $_SERVER[$key] = $snapshot['server'];
    } else {
        unset($_SERVER[$key]);
    }

    putenv($snapshot['process'] === false ? $key : $key . '=' . $snapshot['process']);
}

function arrayKitBenchmarkRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            arrayKitBenchmarkRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}
