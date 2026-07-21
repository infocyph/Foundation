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

it('boots from a sharded lazy cache without loading environment or scanning config files', function (): void {
    $project = configCacheProject([
        '.env' => "APP_NAME=environment\n",
        'config/app.php' => "<?php\n\nreturn ['name' => 'source'];\n",
    ]);
    $environment = [
        'env_exists' => array_key_exists('APP_NAME', $_ENV),
        'env_value' => $_ENV['APP_NAME'] ?? null,
        'server_exists' => array_key_exists('APP_NAME', $_SERVER),
        'server_value' => $_SERVER['APP_NAME'] ?? null,
        'process_value' => getenv('APP_NAME'),
    ];

    try {
        $loader = new ConfigLoader();
        $config = $loader->load(['base_path' => $project]);
        $loader->writeCache($config, $project . '/bootstrap/cache/config');

        $manifest = $project . '/bootstrap/cache/config/__manifest.php';
        $namespace = $project . '/bootstrap/cache/config/app.php';
        expect(fileperms($manifest) & 0777)->toBe(0664)
            ->and(fileperms($namespace) & 0777)->toBe(0664)
            ->and($project . '/bootstrap/cache/config/__flat.php')->not->toBeFile();

        unlink($project . '/.env');
        unlink($project . '/config/app.php');

        $cached = $loader->load([
            'base_path' => $project,
            '_preset' => ['app' => ['debug' => true]],
        ]);

        expect($cached->get('app.name'))->toBe('source')
            ->and($cached->get('app.debug'))->toBeTrue()
            ->and($cached->get('cache.default'))->toBe('memory')
            ->and($cached->lazyNamespaces())->toContain('app', 'auth', 'cache', 'router')
            ->and($manifest)->toBeFile();
    } finally {
        if ($environment['env_exists']) {
            $_ENV['APP_NAME'] = $environment['env_value'];
        } else {
            unset($_ENV['APP_NAME']);
        }

        if ($environment['server_exists']) {
            $_SERVER['APP_NAME'] = $environment['server_value'];
        } else {
            unset($_SERVER['APP_NAME']);
        }

        putenv($environment['process_value'] === false
            ? 'APP_NAME'
            : 'APP_NAME=' . $environment['process_value']);
        configCacheRemoveDirectory($project);
    }
});

it('supports an explicitly configured single config cache', function (): void {
    $project = configCacheProject([
        'config/app.php' => <<<'PHP'
<?php

return [
    'name' => 'single',
    'config_cache' => ['type' => 'single'],
];
PHP,
    ]);

    try {
        $loader = new ConfigLoader();
        $config = $loader->load(['base_path' => $project]);
        $loader->writeCache($config, $project . '/bootstrap/cache/config');

        unlink($project . '/config/app.php');

        $cached = $loader->load(['base_path' => $project]);

        expect($cached->get('app.name'))->toBe('single')
            ->and($project . '/bootstrap/cache/config/__manifest.php')->toBeFile()
            ->and($project . '/bootstrap/cache/config/app.php')->not->toBeFile()
            ->and($project . '/bootstrap/cache/config/__flat.php')->not->toBeFile();
    } finally {
        configCacheRemoveDirectory($project);
    }
});

it('falls back to source config when the cache manifest is invalid', function (): void {
    $project = configCacheProject([
        'config/app.php' => "<?php\n\nreturn ['name' => 'source'];\n",
        'bootstrap/cache/config/__manifest.php' => "<?php\n\nreturn ['_format' => 99];\n",
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
