<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;

it('normalizes immutable build input once before graph composition', function (): void {
    $config = new ConfigRepository([
        'app' => [
            'env' => 'testing',
            'container' => [
                'environment' => 'benchmark',
                'lazy_loading' => false,
                'debug_tracing' => [
                    'enabled' => true,
                    'level' => 'warning',
                ],
            ],
        ],
    ]);

    $context = FoundationBuildContext::fromConfig(
        $config,
        RuntimeMode::Worker,
        ['database' => true, 'cache' => false, 'messaging'],
    );

    expect($context->runtimeMode)->toBe(RuntimeMode::Worker)
        ->and($context->environment)->toBe('benchmark')
        ->and($context->config)->toBe($config->all())
        ->and($context->compiledConfig)->toBeFalse()
        ->and($context->capabilities)->toBe([
            'cache' => false,
            'database' => true,
            'messaging' => true,
        ])
        ->and($context->capabilitiesExplicit)->toBeTrue()
        ->and($context->hasCapability('database'))->toBeTrue()
        ->and($context->hasCapability('cache'))->toBeFalse()
        ->and($context->lazyLoading)->toBeFalse()
        ->and($context->debugTracing)->toBeTrue()
        ->and($context->debugTraceLevel)->toBe(TraceLevelEnum::Warn);
});

it('distinguishes automatic development discovery from an explicitly empty capability graph', function (): void {
    $config = new ConfigRepository(['app' => ['env' => 'testing']]);
    $automatic = FoundationBuildContext::fromConfig($config, RuntimeMode::Cli);
    $minimal = FoundationBuildContext::fromConfig($config, RuntimeMode::Cli, []);

    expect($automatic->capabilities)->toBe([])
        ->and($automatic->capabilitiesExplicit)->toBeFalse()
        ->and($minimal->capabilities)->toBe([])
        ->and($minimal->capabilitiesExplicit)->toBeTrue();
});

it('uses deterministic aliases for every foundation runtime', function (): void {
    expect(RuntimeMode::Web->containerAlias())->toBe('foundation.web')
        ->and(RuntimeMode::Cli->containerAlias())->toBe('foundation.cli')
        ->and(RuntimeMode::Worker->containerAlias())->toBe('foundation.worker')
        ->and(RuntimeMode::Scheduler->containerAlias())->toBe('foundation.scheduler');
});
