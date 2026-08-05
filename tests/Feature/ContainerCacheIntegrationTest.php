<?php

declare(strict_types=1);

use Infocyph\Foundation\Container\ContainerCacheManager;
use Infocyph\Foundation\Foundation;

it('keeps short requests dynamic and activates only matching prevalidated container artifacts', function (): void {
    $project = sys_get_temp_dir() . '/foundation-container-cache-' . bin2hex(random_bytes(5));
    mkdir($project . '/bootstrap/cache', 0777, true);
    $config = [
        'base_path' => $project,
        '_config_cache' => false,
        'app' => [
            'container' => [
                'compiled' => 'bootstrap/cache/container.php',
                'compiled_activation' => 'off',
            ],
        ],
    ];

    try {
        $console = Foundation::console($config);
        $cache = $console->make(ContainerCacheManager::class);
        $report = $cache->compileWeb();
        $manifest = $cache->publishManifest(['config' => 'sharded'], $report);

        $dynamic = Foundation::web($config);
        expect($report['compiled'])->not->toBeEmpty()
            ->and($cache->status()['ready'])->toBeTrue()
            ->and($dynamic->container()->getRepository()->hasCompiledResolvers())->toBeFalse()
            ->and(is_file($manifest))->toBeTrue();

        $config['app']['container']['compiled_activation'] = 'always';
        $compiled = Foundation::web($config);
        expect($compiled->container()->getRepository()->hasCompiledResolvers())->toBeTrue();

        file_put_contents($project . '/bootstrap/cache/container.php', "<?php\n\nreturn [];\n");
        $fallback = Foundation::web($config);
        expect($fallback->container()->getRepository()->hasCompiledResolvers())->toBeFalse()
            ->and($cache->clear())->toBeTrue()
            ->and(is_file($manifest))->toBeFalse();
    } finally {
        foreach (glob($project . '/bootstrap/cache/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($project . '/bootstrap/cache');
        rmdir($project . '/bootstrap');
        rmdir($project);
    }
});
