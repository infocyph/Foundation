<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\FoundationDefaults;

it('does not expose obsolete resolver-map or Foundation route-cache defaults', function (): void {
    $defaults = FoundationDefaults::all();
    $container = $defaults['app']['container'] ?? null;
    $router = $defaults['router'] ?? null;

    expect($container)->toBeArray()
        ->and($container)->not->toHaveKey('alias')
        ->and($container)->not->toHaveKey('compiled')
        ->and($container)->not->toHaveKey('compiled_activation')
        ->and($container)->toHaveKey('environment')
        ->and($container)->toHaveKey('lazy_loading')
        ->and($container)->toHaveKey('debug_tracing')
        ->and($router)->toBeArray()
        ->and($router)->not->toHaveKey('cache');
});
