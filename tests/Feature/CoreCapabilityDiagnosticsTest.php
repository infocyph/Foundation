<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\Internal\ConfiguredCapabilities;
use Infocyph\Foundation\Diagnostics\CoreCapabilityDiagnostics;
use Infocyph\Foundation\Module\ModuleArchitectureCatalog;
use Infocyph\Foundation\Module\ModuleFeaturePlanner;

it('exposes exactly seven prebuilt Core services without turning them into lifecycle features', function (): void {
    $diagnostics = new CoreCapabilityDiagnostics();
    $checks = $diagnostics->checks(new ConfiguredCapabilities(new ConfigRepository([
        'modules' => ['features' => []],
        'app' => ['capabilities' => ['auth', 'cache', 'session']],
    ])));

    expect(array_keys($checks))->toBe([
        'core:auth', 'core:cache', 'core:logging', 'core:operations',
        'core:resources', 'core:session', 'core:notifications',
    ]);
    foreach ($checks as $name => $check) {
        expect($check['ready'])->toBeTrue()
            ->and($check['detail'])->toContain('not installable as a module')
            ->and($name)->toStartWith('core:');
    }
    expect($checks['core:notifications']['detail'])->toContain('disabled by application policy')
        ->and($checks['core:logging']['detail'])->toContain('available by policy');
});

it('keeps Core/QA identities outside the three optional modules and nine feature planner', function (): void {
    $catalog = new ModuleArchitectureCatalog();
    $planner = new ModuleFeaturePlanner();
    $context = [
        'root_known' => true,
        'root_requirements' => [],
        'installed' => [],
        'enabled' => [],
        'config' => [],
        'extensions' => ['pdo', 'fileinfo', 'ctype', 'hash', 'mbstring'],
    ];
    expect(array_keys($catalog->all()))->toBe(['data', 'security', 'execution'])
        ->and(fn() => $planner->plan('install', ['core.cache'], $context))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $planner->plan('remove', ['qa.phpforge'], $context))
        ->toThrow(InvalidArgumentException::class);
});
