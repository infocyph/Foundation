<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigCacheManager;
use Infocyph\Foundation\Foundation;
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
            ->and(fileperms($path) & 0777)->toBe(0600)
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
