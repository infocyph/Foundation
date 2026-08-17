<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Config\LocalPreset;
use Infocyph\Foundation\Config\ProductionPreset;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Foundation;

it('keeps the development fallback in runtime policy instead of application config', function (): void {
    $application = Foundation::preset(RuntimeMode::Cli, new LocalPreset(), [
        '_config_cache' => false,
    ]);

    expect($application->config()->get('auth.token_secret'))->toBeNull()
        ->and((new AuthSecretResolver($application))->tokenSecret())->toBe('foundation-dev-secret');
});

it('rejects a missing production token secret at the point of use', function (): void {
    $application = Foundation::preset(RuntimeMode::Cli, new ProductionPreset(), [
        '_config_cache' => false,
    ]);

    expect(fn(): string => (new AuthSecretResolver($application))->tokenSecret())
        ->toThrow(ConfigurationException::class, 'auth.token_secret must be configured in production.');
});

it('accepts an explicit high-entropy production token secret', function (): void {
    $secret = bin2hex(random_bytes(32));
    $application = Foundation::preset(RuntimeMode::Cli, new ProductionPreset(), [
        '_config_cache' => false,
        'auth' => [
            'token_secret' => $secret,
        ],
    ]);

    expect((new AuthSecretResolver($application))->tokenSecret())->toBe($secret);
});
