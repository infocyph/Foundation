<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;

it('boots the local preset', function (): void {
    $app = Foundation::local([
        'base_path' => dirname(__DIR__, 2),
    ]);

    expect($app->config()->get('app.env'))->toBe('local');
    expect($app->storagePath())->toEndWith('storage');
});

it('boots production when passkey auth is disabled', function (): void {
    $app = Foundation::production([
        'auth' => [
            'drivers' => [
                'passkey' => 'disabled',
            ],
        ],
    ]);

    expect($app->config()->get('auth.drivers.passkey'))->toBe('disabled');
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

    $app = Foundation::local([
        'base_path' => $basePath,
    ]);

    $report = $app->readinessReport();

    expect($report)->toHaveKey('paths');
    expect($report['paths']['issues'] ?? [])->toBeArray()->toBeEmpty();
});
