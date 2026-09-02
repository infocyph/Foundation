<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;

it('normalizes the foundation build context once before graph composition', function (): void {
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

    $context = FoundationBuildContext::fromConfig($config, RuntimeMode::Worker);

    expect($context->runtimeMode)->toBe(RuntimeMode::Worker)
        ->and($context->environment)->toBe('benchmark')
        ->and($context->lazyLoading)->toBeFalse()
        ->and($context->debugTracing)->toBeTrue()
        ->and($context->debugTraceLevel)->toBe(TraceLevelEnum::Warn);
});

it('uses deterministic aliases for every foundation runtime', function (): void {
    expect(RuntimeMode::Web->containerAlias())->toBe('foundation.web')
        ->and(RuntimeMode::Cli->containerAlias())->toBe('foundation.cli')
        ->and(RuntimeMode::Worker->containerAlias())->toBe('foundation.worker')
        ->and(RuntimeMode::Scheduler->containerAlias())->toBe('foundation.scheduler');
});
