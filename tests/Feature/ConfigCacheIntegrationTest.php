<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigLoader;

it('loads lazy namespace caches before project config files', function (): void {
    $project = configCacheProject([
        'config/app.php' => "<?php\n\nreturn ['name' => 'source'];\n",
    ]);

    try {
        $config = (new ConfigLoader())->load(['base_path' => $project]);
        $config->warmLazyCache();
        file_put_contents($project . '/config/app.php', "<?php\n\nreturn ['name' => 'changed'];\n");

        $cached = (new ConfigLoader())->load(['base_path' => $project]);

        expect($cached->get('app.name'))->toBe('source')
            ->and($project . '/bootstrap/cache/config/app.php')->toBeFile()
            ->and($project . '/bootstrap/cache/config/__flat.php')->toBeFile();
    } finally {
        configCacheRemoveDirectory($project);
    }
});

it('can bypass and clear lazy config caches', function (): void {
    $project = configCacheProject([
        'config/app.php' => "<?php\n\nreturn ['name' => 'source'];\n",
    ]);

    try {
        $cached = (new ConfigLoader())->load(['base_path' => $project]);
        $cached->warmLazyCache();
        file_put_contents($project . '/config/app.php', "<?php\n\nreturn ['name' => 'changed'];\n");

        $bypassed = (new ConfigLoader())->load([
            'base_path' => $project,
            '_config_cache' => false,
        ]);

        $cached->clearLazyCache();

        expect($bypassed->get('app.name'))->toBe('changed')
            ->and($project . '/bootstrap/cache/config/app.php')->not->toBeFile();
    } finally {
        configCacheRemoveDirectory($project);
    }
});

it('applies an active preset over lazy project config', function (): void {
    $project = configCacheProject([
        'config/app.php' => "<?php\n\nreturn ['name' => 'source'];\n",
    ]);

    try {
        $cached = (new ConfigLoader())->load(['base_path' => $project]);
        $cached->warmLazyCache();

        $config = (new ConfigLoader())->load([
            'base_path' => $project,
            '_preset' => ['app' => ['debug' => true]],
        ]);

        expect($config->get('app.name'))->toBe('source')
            ->and($config->get('app.debug'))->toBeTrue();
    } finally {
        configCacheRemoveDirectory($project);
    }
});

it('falls back to source config when a lazy cache file is invalid', function (): void {
    $project = configCacheProject([
        'config/app.php' => "<?php\n\nreturn ['name' => 'source'];\n",
        'bootstrap/cache/config/app.php' => "<?php\n\nreturn 'invalid';\n",
    ]);

    try {
        $config = (new ConfigLoader())->load(['base_path' => $project]);

        expect($config->get('app.name'))->toBe('source');
    } finally {
        configCacheRemoveDirectory($project);
    }
});

/**
 * @param array<string, string> $files
 */
function configCacheProject(array $files): string
{
    $root = sys_get_temp_dir() . '/foundation-config-cache-' . bin2hex(random_bytes(5));

    foreach ($files as $path => $contents) {
        $target = $root . DIRECTORY_SEPARATOR . $path;
        $directory = dirname($target);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($target, $contents);
    }

    return $root;
}

function configCacheRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            configCacheRemoveDirectory($path);

            continue;
        }

        unlink($path);
    }

    rmdir($directory);
}
