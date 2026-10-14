<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Module\ModuleFeatureConfigPublisher;

function featureConfigBasePath(): string
{
    $path = sys_get_temp_dir() . '/foundation-feature-config-' . bin2hex(random_bytes(6));
    mkdir($path, 0775, true);

    return $path;
}

/** @return array<string,mixed> */
function featureConfigContext(): array
{
    return [
        'root_known' => true,
        'root_requirements' => [],
        'installed' => [],
        'enabled' => [],
        'config' => [],
        'extensions' => ['pdo', 'fileinfo', 'hash', 'mbstring', 'ctype'],
        'published_configs' => [],
    ];
}

function featureConfigCleanup(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

it('publishes only selected feature config without enabling siblings', function (): void {
    $path = featureConfigBasePath();

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $publisher = new ModuleFeatureConfigPublisher($app);
        $result = $publisher->publish(['execution.communication'], featureConfigContext());

        expect($result['planned'])->toBe(['communication.php'])
            ->and($result['published'])->toBe([$path . '/config/communication.php'])
            ->and($result['existing'])->toBe([])
            ->and($path . '/config/communication.php')->toBeFile()
            ->and($path . '/config/messaging.php')->not->toBeFile()
            ->and($path . '/config/modules.php')->not->toBeFile();
    } finally {
        featureConfigCleanup($path);
    }
});

it('includes conditional prerequisites while retaining application-owned config', function (): void {
    $path = featureConfigBasePath();
    mkdir($path . '/config');
    $applicationOwned = "<?php\nreturn ['owned' => true];\n";
    file_put_contents($path . '/config/database.php', $applicationOwned);
    $context = featureConfigContext();
    $context['config'] = ['messaging' => ['durable' => ['enabled' => true]]];

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $publisher = new ModuleFeatureConfigPublisher($app);
        $result = $publisher->publish(['execution.messaging'], $context);

        expect($result['planned'])->toBe(['database.php', 'messaging.php'])
            ->and($result['published'])->toBe([$path . '/config/messaging.php'])
            ->and($result['existing'])->toBe([$path . '/config/database.php'])
            ->and(file_get_contents($path . '/config/database.php'))->toBe($applicationOwned)
            ->and($path . '/config/messaging.php')->toBeFile();
    } finally {
        featureConfigCleanup($path);
    }
});

it('plans configuration without disk mutations in dry-run mode', function (): void {
    $path = featureConfigBasePath();

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $publisher = new ModuleFeatureConfigPublisher($app);
        $result = $publisher->publish(['data.database'], featureConfigContext(), true);

        expect($result)->toBe(['planned' => ['database.php'], 'published' => [], 'existing' => []])
            ->and($path . '/config')->not->toBeDirectory();
    } finally {
        featureConfigCleanup($path);
    }
});

it('rejects missing platform prerequisites without creating config', function (): void {
    $path = featureConfigBasePath();
    $context = featureConfigContext();
    $context['extensions'] = [];

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $publisher = new ModuleFeatureConfigPublisher($app);

        expect(fn() => $publisher->publish(['data.database'], $context))
            ->toThrow(RuntimeException::class, 'Extension pdo is required')
            ->and($path . '/config')->not->toBeDirectory();
    } finally {
        featureConfigCleanup($path);
    }
});
