<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Module\ModuleActivationManager;
use Infocyph\Foundation\Module\ModuleCatalog;

/** @return array<string,mixed> */
function featureActivationContext(): array
{
    return [
        'root_known' => true,
        'root_requirements' => [
            'infocyph/otp' => '^7.0',
            'web-auth/webauthn-lib' => '^5.3.9',
        ],
        'installed' => [
            'infocyph/otp' => '7.0.0',
            'web-auth/webauthn-lib' => '5.3.9',
        ],
        'enabled' => [],
        'config' => [],
        'extensions' => ['pdo', 'fileinfo', 'hash', 'mbstring', 'ctype'],
    ];
}

function featureActivationBasePath(): string
{
    $path = sys_get_temp_dir() . '/foundation-feature-activation-' . bin2hex(random_bytes(6));
    mkdir($path . '/config', 0775, true);

    return $path;
}

function featureActivationCleanup(string $path): void
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

it('enables only the selected feature and preserves legacy capability policy', function (): void {
    $path = featureActivationBasePath();
    file_put_contents($path . '/config/modules.php', "<?php\nreturn ['capabilities' => ['filesystem' => false], 'owner' => 'app'];\n");

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $activation = new ModuleActivationManager($app, new ModuleCatalog());
        $result = $activation->applyFeatures('enable', ['security.passkey'], featureActivationContext());
        $config = require $result;

        expect($result)->toBe($path . '/config/modules.php')
            ->and($config['features'])->toBe(['security.passkey' => true])
            ->and($config['capabilities'])->toBe(['filesystem' => false])
            ->and($config['owner'])->toBe('app')
            ->and($path . '/config/modules.php.lock')->toBeFile();
    } finally {
        featureActivationCleanup($path);
    }
});

it('disables one feature without changing enabled siblings or application policy', function (): void {
    $path = featureActivationBasePath();
    file_put_contents($path . '/config/modules.php', "<?php\nreturn ['features' => ['security.mfa' => true, 'security.passkey' => true], 'capabilities' => ['filesystem' => true]];\n");

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $activation = new ModuleActivationManager($app, new ModuleCatalog());
        $context = featureActivationContext();
        $context['enabled'] = ['security.mfa', 'security.passkey'];
        $pathToConfig = $activation->applyFeatures('disable', ['security.mfa'], $context);
        $config = require $pathToConfig;

        expect($config['features'])->toBe(['security.mfa' => false, 'security.passkey' => true])
            ->and($config['capabilities'])->toBe(['filesystem' => true]);
    } finally {
        featureActivationCleanup($path);
    }
});

it('refuses stale feature activation snapshots without overwriting application config', function (): void {
    $path = featureActivationBasePath();
    $original = "<?php\nreturn ['features' => ['data.filesystem' => true]];\n";
    file_put_contents($path . '/config/modules.php', $original);

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $activation = new ModuleActivationManager($app, new ModuleCatalog());
        $context = featureActivationContext();

        expect(fn() => $activation->applyFeatures('disable', ['data.filesystem'], $context))
            ->toThrow(RuntimeException::class, 'Feature activation changed')
            ->and(file_get_contents($path . '/config/modules.php'))->toBe($original);
    } finally {
        featureActivationCleanup($path);
    }
});

it('refuses to disable a dependency while an enabled feature still requires it', function (): void {
    $path = featureActivationBasePath();
    $original = "<?php\nreturn ['features' => ['data.database' => true, 'execution.messaging' => true]];\n";
    file_put_contents($path . '/config/modules.php', $original);

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $activation = new ModuleActivationManager($app, new ModuleCatalog());
        $context = featureActivationContext();
        $context['enabled'] = ['data.database', 'execution.messaging'];
        $context['config'] = ['messaging' => ['durable' => ['enabled' => true]]];

        expect(fn() => $activation->applyFeatures('disable', ['data.database'], $context))
            ->toThrow(RuntimeException::class, 'still depends on data.database')
            ->and(file_get_contents($path . '/config/modules.php'))->toBe($original);
    } finally {
        featureActivationCleanup($path);
    }
});

it('rejects symlink activation lock paths and unsupported feature operations', function (): void {
    $path = featureActivationBasePath();
    $outside = $path . '/protected';
    file_put_contents($outside, 'preserve');
    symlink($outside, $path . '/config/modules.php.lock');

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $activation = new ModuleActivationManager($app, new ModuleCatalog());
        $context = featureActivationContext();

        expect(fn() => $activation->applyFeatures('enable', ['security.passkey'], $context))
            ->toThrow(RuntimeException::class, 'Refusing symbolic-linked module activation lock')
            ->and(fn() => $activation->applyFeatures('install', ['security.passkey'], $context))
            ->toThrow(InvalidArgumentException::class, 'Feature activation requires')
            ->and(file_get_contents($outside))->toBe('preserve')
            ->and($path . '/config/modules.php')->not->toBeFile();
    } finally {
        featureActivationCleanup($path);
    }
});

it('serializes concurrent module writers without losing unrelated activation overrides', function (): void {
    $path = featureActivationBasePath();
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    $barrier = $path . '/start';
    $processes = [];

    try {
        foreach (['filesystem', 'validation'] as $module) {
            $script = 'require ' . var_export($autoload, true) . ';'
                . 'while (!is_file(' . var_export($barrier, true) . ')) { usleep(1000); }'
                . '$application = \Infocyph\Foundation\Foundation::cli(['
                . "'base_path' => " . var_export($path, true) . ", '_config_cache' => false]);"
                . '(new \Infocyph\Foundation\Module\ModuleActivationManager('
                . '$application, new \Infocyph\Foundation\Module\ModuleCatalog()))'
                . '->disable(' . var_export($module, true) . ');';

            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $script],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start concurrent activation fixture.');
            }
            fclose($pipes[0]);
            $processes[] = [$process, $pipes[1], $pipes[2]];
        }

        file_put_contents($barrier, 'go');
        foreach ($processes as [$process, $stdout, $stderr]) {
            stream_get_contents($stdout);
            $error = stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            expect(proc_close($process))->toBe(0, $error);
        }
        $processes = [];

        $config = require $path . '/config/modules.php';
        expect($config['capabilities'])->toBe(['filesystem' => false, 'validation' => false]);
    } finally {
        foreach ($processes as [$process, $stdout, $stderr]) {
            fclose($stdout);
            fclose($stderr);
            proc_terminate($process);
            proc_close($process);
        }
        featureActivationCleanup($path);
    }
});

it('does not rewrite config or invalidate its cache for unchanged feature topology', function (): void {
    $path = featureActivationBasePath();
    $config = $path . '/config/modules.php';
    file_put_contents($config, "<?php\nreturn ['features' => ['security.passkey' => true]];\n");
    $cacheDir = $path . '/bootstrap/cache/config';
    mkdir($cacheDir, 0775, true);
    file_put_contents($cacheDir . '/__manifest.php', '<?php return [];');

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $activation = new ModuleActivationManager($app, new ModuleCatalog());
        $context = featureActivationContext();
        $context['enabled'] = ['security.passkey'];

        clearstatcache(true, $config);
        $inode = fileinode($config);
        $original = file_get_contents($config);
        $result = $activation->applyFeatures('enable', ['security.passkey'], $context);
        clearstatcache(true, $config);

        expect($result)->toBe($config)
            ->and(fileinode($config))->toBe($inode)
            ->and(file_get_contents($config))->toBe($original)
            ->and($cacheDir . '/__manifest.php')->toBeFile();
    } finally {
        featureActivationCleanup($path);
    }
});

it('rejects an enabled feature removed from the authoritative on-disk snapshot', function (): void {
    $path = featureActivationBasePath();
    $original = "<?php\nreturn ['features' => []];\n";
    file_put_contents($path . '/config/modules.php', $original);

    try {
        $app = Foundation::cli(['base_path' => $path, '_config_cache' => false]);
        $activation = new ModuleActivationManager($app, new ModuleCatalog());
        $context = featureActivationContext();
        $context['enabled'] = ['security.crypto'];
        $context['selected_features'] = ['security.crypto'];

        expect(fn() => $activation->applyFeatures('enable', ['security.passkey'], $context))
            ->toThrow(RuntimeException::class)
            ->and(file_get_contents($path . '/config/modules.php'))->toBe($original);
    } finally {
        featureActivationCleanup($path);
    }
});
