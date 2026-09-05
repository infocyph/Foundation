<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Routing\RouteMiddlewareRegistrar;
use Infocyph\Foundation\Routing\RoutePresetRegistrar;

it('normalizes built-in aliases and configured route preset stacks', function (): void {
    $application = Foundation::web(['_config_cache' => false]);
    $presets = new RoutePresetRegistrar(
        $application->make(RouteMiddlewareRegistrar::class),
        new ConfigRepository([
            'router' => [
                'middleware' => [
                    'groups' => [
                        'custom' => ['resolve-auth', '', 'auth', 42, 'verified'],
                    ],
                ],
            ],
        ]),
    );

    expect($presets->stack('api-auth'))->toBe(['resolve-auth', 'auth'])
        ->and($presets->stack('auth:mfa'))->toBe(['resolve-auth', 'auth', 'mfa'])
        ->and($presets->stack('auth:web'))->toBe(['session', 'csrf', 'resolve-auth', 'auth'])
        ->and($presets->stack('custom'))->toBe(['resolve-auth', 'auth', 'verified'])
        ->and($presets->stack('unknown'))->toBe([]);
});
