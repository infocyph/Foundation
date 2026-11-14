<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigCacheManager;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Release\FoundationReleaseConfig;
use Infocyph\Foundation\Security\EnvironmentSecretManager;
use Infocyph\Foundation\Tests\Fixtures\EnvironmentSecretStageObserver;

require_once dirname(__DIR__) . '/Fixtures/EnvironmentSecretStageObserver.php';
require_once dirname(__DIR__) . '/Fixtures/environment_secret_stage_hooks.php';

it('protects .env stage before first plaintext write and retains old file on rejected permission', function (): void {
    $root = sys_get_temp_dir() . '/foundation-secret-stage-' . bin2hex(random_bytes(8));
    mkdir($root, 0755, true);
    $path = $root . '/.env';
    file_put_contents($path, "APP_ENV=testing\nSECRET_VALUE=existing-private-secret\n");
    chmod($path, 0600);
    $previousUmask = umask(0022);

    try {
        $app = Foundation::cli(['app' => ['base_path' => $root], '_config_cache' => false]);
        $manager = new EnvironmentSecretManager($app, new ConfigCacheManager($app));

        EnvironmentSecretStageObserver::$active = true;
        EnvironmentSecretStageObserver::$modeAtFirstWrite = null;
        EnvironmentSecretStageObserver::$writes = 0;
        $manager->generate(force: true);
        expect(EnvironmentSecretStageObserver::$modeAtFirstWrite)->toBe(0600)
            ->and(EnvironmentSecretStageObserver::$writes)->toBeGreaterThan(0)
            ->and((($__f158_mode = fileperms($path)) === false ? throw new \RuntimeException('Unable to read staged secret permissions.') : $__f158_mode) & 0777)->toBe(0600)
            ->and(file_get_contents($path))->toContain('SECRET_VALUE=existing-private-secret')
            ->and(((array) glob($root . '/.env.*.tmp')))->toBe([]);

        $saved = file_get_contents($path);
        EnvironmentSecretStageObserver::$failChmod = true;
        EnvironmentSecretStageObserver::$modeAtFirstWrite = null;
        EnvironmentSecretStageObserver::$writes = 0;
        expect(fn() => $manager->generate(force: true))
            ->toThrow(RuntimeException::class, 'Unable to secure the generated environment file.');
        expect(EnvironmentSecretStageObserver::$writes)->toBe(0)
            ->and(file_get_contents($path))->toBe($saved)
            ->and(((array) glob($root . '/.env.*.tmp')))->toBe([]);
    } finally {
        EnvironmentSecretStageObserver::$active = false;
        EnvironmentSecretStageObserver::$failChmod = false;
        EnvironmentSecretStageObserver::$modeAtFirstWrite = null;
        EnvironmentSecretStageObserver::$writes = 0;
        umask($previousUmask);
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($root);
    }
});

it('keeps release configuration private throughout staging and preserves the old artifact on failure', function (int $mask): void {
    $root = sys_get_temp_dir() . '/foundation-config-stage-' . bin2hex(random_bytes(8));
    mkdir($root, 0755, true);
    mkdir($root . '/outside', 0755);
    $path = $root . '/config.php';
    $previousUmask = umask($mask);

    try {
        EnvironmentSecretStageObserver::$active = true;
        EnvironmentSecretStageObserver::$modeAtFirstWrite = null;
        EnvironmentSecretStageObserver::$writes = 0;
        $sha256 = FoundationReleaseConfig::write($path, ['database' => ['password' => 'release-stage-test-secret']]);
        expect(EnvironmentSecretStageObserver::$modeAtFirstWrite)->toBe(0600)
            ->and(EnvironmentSecretStageObserver::$writes)->toBeGreaterThan(0)
            ->and(FoundationReleaseConfig::load($path, $sha256)->get('database.password'))->toBe('release-stage-test-secret')
            ->and(glob($root . '/.foundation-config-*'))->toBe([]);

        $saved = file_get_contents($path);
        EnvironmentSecretStageObserver::$failChmod = true;
        expect(fn() => FoundationReleaseConfig::write($path, ['database' => ['password' => 'replacement-secret']]))
            ->toThrow(RuntimeException::class, 'Unable to publish Foundation release config');
        expect(file_get_contents($path))->toBe($saved)
            ->and(glob($root . '/.foundation-config-*'))->toBe([]);

        EnvironmentSecretStageObserver::$failChmod = false;
        EnvironmentSecretStageObserver::$temporaryDirectory = $root . '/outside';
        EnvironmentSecretStageObserver::$writes = 0;
        expect(fn() => FoundationReleaseConfig::write($path, ['database' => ['password' => 'replacement-secret']]))
            ->toThrow(RuntimeException::class, 'staging directory');
        expect(EnvironmentSecretStageObserver::$writes)->toBe(0)
            ->and(file_get_contents($path))->toBe($saved)
            ->and(glob($root . '/outside/.foundation-config-*'))->toBe([]);
    } finally {
        EnvironmentSecretStageObserver::$active = false;
        EnvironmentSecretStageObserver::$failChmod = false;
        EnvironmentSecretStageObserver::$modeAtFirstWrite = null;
        EnvironmentSecretStageObserver::$writes = 0;
        EnvironmentSecretStageObserver::$temporaryDirectory = null;
        EnvironmentSecretStageObserver::$configDirectoryMode = null;
        umask($previousUmask);
        $localStages = glob($root . '/.foundation-config-*');
        $outsideStages = glob($root . '/outside/.foundation-config-*');
        foreach ([...($localStages === false ? [] : $localStages), ...($outsideStages === false ? [] : $outsideStages)] as $temporary) {
            unlink($temporary);
        }
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($root . '/outside');
        rmdir($root);
    }
})->with(['default umask' => 0022, 'permissive umask' => 0000]);
