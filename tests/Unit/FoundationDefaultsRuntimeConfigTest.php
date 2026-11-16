<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\FoundationDefaults;

it('does not expose obsolete resolver-map or Foundation route-cache defaults', function (): void {
    $defaults = FoundationDefaults::all();
    \PHPUnit\Framework\Assert::assertIsArray(($defaults['app'] ?? null));
    $container = $defaults['app']['container'] ?? null;
    $router = $defaults['router'] ?? null;

    \PHPUnit\Framework\Assert::assertIsArray($container);
    expect($container)->not->toHaveKey('alias')
        ->and($container)->not->toHaveKey('compiled')
        ->and($container)->not->toHaveKey('compiled_activation')
        ->and($container)->toHaveKey('environment')
        ->and($container)->toHaveKey('lazy_loading')
        ->and($container)->toHaveKey('debug_tracing')
        ->and($router)->toBeArray()
        ->and($router)->not->toHaveKey('cache');
});
