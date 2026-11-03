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
                        'custom' => ['resolve-auth', 'auth', 'verified'],
                        'empty' => [],
                    ],
                ],
            ],
        ]),
    );

    expect($presets->stack('api-auth'))->toBe(['resolve-auth', 'auth'])
        ->and($presets->stack('auth:mfa'))->toBe(['resolve-auth', 'auth', 'mfa'])
        ->and($presets->stack('auth:web'))->toBe(['session', 'csrf', 'resolve-auth', 'auth'])
        ->and($presets->stack('custom'))->toBe(['resolve-auth', 'auth', 'verified'])
        ->and($presets->stack('empty'))->toBe([])
        ->and(fn() => $presets->stack('web-aut'))->toThrow(InvalidArgumentException::class, 'Unknown route middleware preset');
});

it('rejects configured middleware stacks that could remove security middleware', function (): void {
    $application = Foundation::web(['_config_cache' => false]);
    foreach ([
        ['groups' => ['web-auth' => ['session', 14, 'auth']]],
        ['groups' => ['web-auth' => ['session', '', 'auth']]],
        ['groups' => ['web-auth' => 'session']],
    ] as $middleware) {
        $presets = new RoutePresetRegistrar(
            $application->make(RouteMiddlewareRegistrar::class),
            new ConfigRepository(['router' => ['middleware' => $middleware]]),
        );
        expect(fn() => $presets->stack('web-auth'))->toThrow(InvalidArgumentException::class);
    }
});
