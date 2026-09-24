<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\FilesystemPublicFileResolver;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Pathwise\Storage\StorageContext;
use Infocyph\Pathwise\StreamHandler\PublicFileResolver;
use Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport;

require dirname(__DIR__) . '/vendor/autoload.php';
function pathwise41Remove(string $directory): void
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
$base = sys_get_temp_dir() . '/foundation-pathwise41-bench-' . bin2hex(random_bytes(5));
mkdir($base . '/storage/uploads', 0775, true);
mkdir($base . '/storage/public', 0775, true);
mkdir($base . '/public', 0775, true);
file_put_contents($base . '/public/asset.txt', 'public-benchmark');

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
$directPublic = new PublicFileResolver();
$foundationPublic = new FilesystemPublicFileResolver($foundationConfig, $paths);
$context->filesystem('uploads');
$registry->disk('uploads');

try {
    $subjects = [];
    $subjects['direct_context_construct_two_disks'] = BenchmarkSupport::measure(
        static fn() => new StorageContext($directConfigs, 'uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_registry_construct_two_disks'] = BenchmarkSupport::measure(
        static fn() => new StorageRegistry($foundationConfig, $paths),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['direct_context_path'] = BenchmarkSupport::measure(
        static fn() => $context->path('bench/file.txt', 'uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_registry_path'] = BenchmarkSupport::measure(
        static fn() => $registry->path('bench/file.txt', 'uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['direct_context_local_path'] = BenchmarkSupport::measure(
        static fn() => $context->localPath('bench/file.txt', 'uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_registry_local_path'] = BenchmarkSupport::measure(
        static fn() => $registry->localPath('bench/file.txt', 'uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['direct_context_warm_filesystem'] = BenchmarkSupport::measure(
        static fn() => $context->filesystem('uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_registry_warm_disk'] = BenchmarkSupport::measure(
        static fn() => $registry->disk('uploads'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['direct_public_file_resolution'] = BenchmarkSupport::measure(
        static fn() => $directPublic->resolve($base . '/public', 'asset.txt'),
        $operations,
        $repetitions,
        $warmup,
    );
    $subjects['foundation_public_file_resolution'] = BenchmarkSupport::measure(
        static fn() => $foundationPublic->resolve('asset.txt'),
        $operations,
        $repetitions,
        $warmup,
    );

    $report = [
        'benchmark' => 'foundation-pathwise-4.1-utilization',
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
            'registry_construct_vs_context' => BenchmarkSupport::ratio(
                (float) $subjects['foundation_registry_construct_two_disks']['median_ns'],
                (float) $subjects['direct_context_construct_two_disks']['median_ns'],
            ),
            'registry_path_vs_context' => BenchmarkSupport::ratio(
                (float) $subjects['foundation_registry_path']['median_ns'],
                (float) $subjects['direct_context_path']['median_ns'],
            ),
            'registry_local_path_vs_context' => BenchmarkSupport::ratio(
                (float) $subjects['foundation_registry_local_path']['median_ns'],
                (float) $subjects['direct_context_local_path']['median_ns'],
            ),
            'registry_warm_disk_vs_context' => BenchmarkSupport::ratio(
                (float) $subjects['foundation_registry_warm_disk']['median_ns'],
                (float) $subjects['direct_context_warm_filesystem']['median_ns'],
            ),
            'public_resolution_bridge_vs_direct' => BenchmarkSupport::ratio(
                (float) $subjects['foundation_public_file_resolution']['median_ns'],
                (float) $subjects['direct_public_file_resolution']['median_ns'],
            ),
        ],
        'decision_input' => [
            'storage_context_global_mounts' => false,
            'foundation_bridge_policy_only' => true,
            'note' => 'Pathwise owns lower-layer upload, malware, range, symlink and adapter benchmarks; this records Foundation topology/path lookup plus trusted public-root policy adapter attribution.',
        ],
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 3),
    ];

    file_put_contents(
        'php://stdout',
        json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    );
} finally {
    pathwise41Remove($base);
}
