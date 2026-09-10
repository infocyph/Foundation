<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Pathwise\Storage\StorageContext;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array{median_ns:float,min_ns:float,max_ns:float,spread_percent:float} */
function pathwise40Measure(callable $operation, int $operations, int $repetitions, int $warmup): array
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

function pathwise40Ratio(float $numerator, float $denominator): float
{
    return round($numerator / max(1.0, $denominator), 4);
}

function pathwise40Remove(string $directory): void
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

$operations = max(100, (int) (getenv('PATHWISE_RUNTIME_OPERATIONS') ?: 2_000));
$repetitions = max(3, (int) (getenv('PATHWISE_RUNTIME_REPETITIONS') ?: 7));
$warmup = max(20, (int) (getenv('PATHWISE_RUNTIME_WARMUP') ?: 100));
$base = sys_get_temp_dir() . '/foundation-pathwise40-bench-' . bin2hex(random_bytes(5));
mkdir($base . '/storage/uploads', 0775, true);
mkdir($base . '/storage/public', 0775, true);

$directConfigs = [
    'uploads' => ['driver' => 'local', 'root' => $base . '/storage/uploads'],
    'public' => ['driver' => 'local', 'root' => $base . '/storage/public'],
];
$foundationConfig = new ConfigRepository([
    'filesystem' => [
        'default' => 'uploads',
        'disks' => [
            'uploads' => ['driver' => 'local', 'root' => 'storage/uploads'],
            'public' => ['driver' => 'local', 'root' => 'storage/public'],
        ],
    ],
]);
$paths = new PathManager($base);
$context = new StorageContext($directConfigs, 'uploads');
$registry = new StorageRegistry($foundationConfig, $paths);
$context->filesystem('uploads');
$registry->disk('uploads');

try {
    $subjects = [];
    $subjects['direct_context_construct_two_disks'] = pathwise40Measure(
        static fn() => new StorageContext($directConfigs, 'uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_registry_construct_two_disks'] = pathwise40Measure(
        static fn() => new StorageRegistry($foundationConfig, $paths),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['direct_context_path'] = pathwise40Measure(
        static fn() => $context->path('bench/file.txt', 'uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_registry_path'] = pathwise40Measure(
        static fn() => $registry->path('bench/file.txt', 'uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['direct_context_local_path'] = pathwise40Measure(
        static fn() => $context->localPath('bench/file.txt', 'uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_registry_local_path'] = pathwise40Measure(
        static fn() => $registry->localPath('bench/file.txt', 'uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['direct_context_warm_filesystem'] = pathwise40Measure(
        static fn() => $context->filesystem('uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_registry_warm_disk'] = pathwise40Measure(
        static fn() => $registry->disk('uploads'),
        $operations,
        $repetitions,
        $warmup,
    );

    $report = [
        'benchmark' => 'foundation-pathwise-4.0-utilization',
        'versions' => [
            'php' => PHP_VERSION,
            'foundation' => InstalledVersions::getPrettyVersion('infocyph/foundation'),
            'pathwise' => InstalledVersions::getPrettyVersion('infocyph/pathwise'),
        ],
        'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions' : 'local-cli',
        'operations_per_repetition' => $operations,
        'repetitions' => $repetitions,
        'warmup_operations' => $warmup,
        'subjects' => $subjects,
        'ratios' => [
            'registry_construct_vs_context' => pathwise40Ratio(
                (float) $subjects['foundation_registry_construct_two_disks']['median_ns'],
                (float) $subjects['direct_context_construct_two_disks']['median_ns'],
            ),
            'registry_path_vs_context' => pathwise40Ratio(
                (float) $subjects['foundation_registry_path']['median_ns'],
                (float) $subjects['direct_context_path']['median_ns'],
            ),
            'registry_local_path_vs_context' => pathwise40Ratio(
                (float) $subjects['foundation_registry_local_path']['median_ns'],
                (float) $subjects['direct_context_local_path']['median_ns'],
            ),
            'registry_warm_disk_vs_context' => pathwise40Ratio(
                (float) $subjects['foundation_registry_warm_disk']['median_ns'],
                (float) $subjects['direct_context_warm_filesystem']['median_ns'],
            ),
        ],
        'decision_input' => [
            'storage_context_global_mounts' => false,
            'foundation_bridge_policy_only' => true,
            'note' => 'Pathwise owns lower-layer upload, malware, range, symlink and adapter benchmarks; this records only Foundation topology/path lookup attribution.',
        ],
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 3),
    ];

    file_put_contents(
        'php://stdout',
        json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    );
} finally {
    pathwise40Remove($base);
}
