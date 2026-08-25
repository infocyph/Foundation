<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Container\ContainerCacheManager;
use Infocyph\Foundation\Foundation;

it('keeps short requests dynamic and activates only matching prevalidated runtime artifacts', function (): void {
    $project = sys_get_temp_dir() . '/foundation-container-cache-' . bin2hex(random_bytes(5));
    mkdir($project . '/bootstrap/cache', 0777, true);
    $config = [
        'base_path' => $project,
        '_config_cache' => false,
        'app' => [
            'container' => [
                'compiled' => 'bootstrap/cache/container/{runtime}.php',
                'compiled_activation' => 'off',
            ],
        ],
    ];

    try {
        $application = Foundation::cli($config);
        $cache = $application->make(ContainerCacheManager::class);
        $report = $cache->compile(RuntimeMode::Web);
        $manifest = $cache->publishManifest(['config' => 'sharded'], ['web' => $report]);

        $dynamic = Foundation::web($config);
        expect($report['compiled'])->not->toBeEmpty()
            ->and($cache->status(RuntimeMode::Web)['ready'])->toBeTrue()
            ->and($dynamic->container()->getRepository()->hasCompiledResolvers())->toBeFalse()
            ->and($manifest)->toBeFile();

        $config['app']['container']['compiled_activation'] = 'always';
        $compiled = Foundation::web($config);
        expect($compiled->container()->getRepository()->hasCompiledResolvers())->toBeTrue();

        file_put_contents($project . '/bootstrap/cache/container/web.php', "<?php\n\nreturn [];\n");
        $fallback = Foundation::web($config);
        expect($fallback->container()->getRepository()->hasCompiledResolvers())->toBeFalse()
            ->and($cache->clear())->toBeTrue()
            ->and($manifest)->not->toBeFile();
    } finally {
        containerCacheRemoveDirectory($project);
    }
});

function containerCacheRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $entries = scandir($directory);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            containerCacheRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}
