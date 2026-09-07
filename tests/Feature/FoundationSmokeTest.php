<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\LocalPreset;
use Infocyph\Foundation\Config\ProductionPreset;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Diagnostics\ReadinessReport;
use Infocyph\Foundation\Foundation;

it('boots the local preset independently from runtime selection', function (): void {
    $app = Foundation::preset(RuntimeMode::Web, new LocalPreset(), [
        'base_path' => dirname(__DIR__, 2),
    ]);

    expect($app->config()->get('app.env'))->toBe('local');
    expect($app->storagePath())->toEndWith('storage');
    expect($app->config()->has('app.container_alias'))->toBeFalse();
});

it('applies the production preset when passkey auth is disabled', function (): void {
    $config = new ConfigLoader()->load([
        '_preset' => new ProductionPreset()->config(),
        'auth' => [
            'drivers' => [
                'passkey' => 'disabled',
            ],
        ],
    ]);

    expect($config->get('app.env'))->toBe('production')
        ->and($config->get('auth.drivers.passkey'))->toBe('disabled');
});

it('boots with WebAuthn when rp metadata is provided', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => [
                'passkey' => 'webauthn',
            ],
            'webauthn' => [
                'rp_id' => 'example.test',
                'origin' => 'https://example.test',
            ],
        ],
    ]);

    expect($app->config()->get('auth.webauthn.rp_id'))->toBe('example.test');
});

it('includes path awareness in the readiness report', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-smoke-' . uniqid('', true);
    mkdir($basePath . '/storage/cache', 0775, true);
    mkdir($basePath . '/storage/logs', 0775, true);
    mkdir($basePath . '/storage/sessions', 0775, true);
    mkdir($basePath . '/storage/uploads', 0775, true);

    try {
        $app = Foundation::preset(RuntimeMode::Cli, new LocalPreset(), [
            'base_path' => $basePath,
        ]);
        $report = new ReadinessReport($app)->generate();

        expect($report['checks'])->toHaveKeys(['base_path', 'storage', 'runtime'])
            ->and($report['checks']['base_path']['ready'])->toBeTrue()
            ->and($report['checks']['storage']['ready'])->toBeTrue()
            ->and($report['checks']['runtime']['detail'])->toBe('cli');
    } finally {
        foundationSmokeRemoveDirectory($basePath);
    }
});

function foundationSmokeRemoveDirectory(string $directory): void
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
            foundationSmokeRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}
