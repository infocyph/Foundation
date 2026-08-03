<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Routing\RouteMiddlewareRegistrar;
use Infocyph\Foundation\Routing\RoutePresetRegistrar;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;

it('normalizes named route preset calls in one registrar', function (): void {
    $application = Foundation::web(['_config_cache' => false]);
    $presets = new RoutePresetRegistrar(
        new RouteMiddlewareRegistrar($application),
        new ConfigRepository(),
    );
    $router = new Registrar(new Collection());

    expect($presets->invokeNamed($router, 'unknownPreset', []))->toBeFalse()
        ->and($presets->invokeNamed($router, 'apiAuth', [
            static function (): void {},
            'api',
            null,
            'api.',
        ]))->toBeTrue();

    expect(fn(): bool => $presets->invokeNamed($router, 'authWeb', []))
        ->toThrow(InvalidArgumentException::class, 'requires a closure callback')
        ->and(fn(): bool => $presets->invokeNamed($router, 'authWeb', [
            static function (): void {},
            null,
            null,
            null,
            'unexpected',
        ]))->toThrow(InvalidArgumentException::class, 'accepts at most four arguments');
});
