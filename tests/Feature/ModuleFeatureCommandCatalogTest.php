<?php

declare(strict_types=1);

use Infocyph\Foundation\Module\ModuleFeatureCommandCatalog;

it('publishes exactly three public module groups and nine qualified features in schema v2', function (): void {
    $catalog = new ModuleFeatureCommandCatalog();
    $inventory = $catalog->inventory();

    expect($inventory['schema_version'])->toBe(2)
        ->and(array_column($inventory['modules'], 'name'))->toBe(['data', 'security', 'execution'])
        ->and(array_merge(...array_column($inventory['modules'], 'features')))->toBe([
            'data.database', 'data.filesystem', 'security.crypto', 'security.validation',
            'security.mfa', 'security.passkey', 'execution.runtime', 'execution.messaging',
            'execution.communication',
        ]);

    foreach ($inventory['modules'] as $module) {
        expect($module['features'])->not->toBeEmpty()
            ->and($module['name'])->not->toBe('core', 'qa', 'auth', 'cache');
    }
});

it('normalizes group-scoped feature selections without enabling unrequested siblings', function (): void {
    $catalog = new ModuleFeatureCommandCatalog();
    expect($catalog->selection('data.database'))->toBe(['data.database'])
        ->and($catalog->selection('security', ['passkey']))->toBe(['security.passkey'])
        ->and($catalog->selection('security', ['passkey', 'mfa', 'passkey']))->toBe([
            'security.mfa', 'security.passkey',
        ])
        ->and($catalog->selection('execution', ['communication', 'messaging']))->toBe([
            'execution.messaging', 'execution.communication',
        ]);
});

it('refuses bare modules, foreign group features and retired Core or QA aliases', function (): void {
    $catalog = new ModuleFeatureCommandCatalog();
    expect(fn() => $catalog->selection('data'))->toThrow(InvalidArgumentException::class, 'no implicit feature')
        ->and(fn() => $catalog->selection('data', ['security.crypto']))->toThrow(InvalidArgumentException::class, 'does not belong')
        ->and(fn() => $catalog->selection('security.mfa', ['passkey']))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $catalog->selection('cache'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $catalog->selection('auth'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $catalog->selection('core.cache'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $catalog->selection('qa.phpforge'))->toThrow(InvalidArgumentException::class);
});
