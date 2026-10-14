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
