<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Unit\Container;

use Infocyph\Foundation\Application\FoundationBuildContext;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use RuntimeException;

it('creates a fresh deterministic builder for each runtime composition', function (RuntimeMode $runtimeMode): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        $runtimeMode,
    );

    $first = FoundationGraph::compose($context);
    $second = FoundationGraph::compose($context);
    $runtimeFactory = $first->definitionGraph()->definitions()[RuntimeMode::class] ?? null;
    if (!$runtimeFactory instanceof FactoryDefinition) {
        throw new RuntimeException('Runtime factory definition is missing from Foundation graph.');
    }

    expect($runtimeFactory->signature()['class'])->toBe(RuntimeMode::class)
        ->and($runtimeFactory->signature()['method'])->toBe('from');

    expect($first)->not->toBe($second)
        ->and($first->build())->not->toBe($second->build())
        ->and(\foundationService($first->build(), RuntimeMode::class))->toBe($runtimeMode)
        ->and(\foundationService($second->build(), RuntimeMode::class))->toBe($runtimeMode);
})->with(RuntimeMode::cases());

it('keeps core graph values equivalent in development and generated production', function (RuntimeMode $runtimeMode): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository([
            'app' => [
                'env' => 'testing',
                'name' => 'Foundation graph parity',
                'container' => ['lazy_loading' => false],
            ],
        ]),
        $runtimeMode,
        ['cache' => true],
    );
    $builder = FoundationGraph::compose($context);
    $development = $builder->build();

    $artifact = tempnam(sys_get_temp_dir(), 'foundation-graph-');
    if ($artifact === false) {
        throw new RuntimeException('Unable to allocate a temporary InterMix artifact path.');
    }

    try {
        $builder->validate(strict: true);
        $report = $builder->compile($artifact);
        $production = $builder->production($artifact);

        expect($report['skipped'])->not->toHaveKey(ConfigRepository::class)
            ->and($report['skipped'])->not->toHaveKey(RuntimeMode::class)
            ->and(\foundationService($development, RuntimeMode::class))->toBe($runtimeMode)
            ->and(\foundationService($production, RuntimeMode::class))->toBe($runtimeMode)
            ->and(\foundationService($development, ConfigRepository::class)->all())
            ->toBe(\foundationService($production, ConfigRepository::class)->all())
            ->and(\foundationService($production, ConfigRepository::class)->get('app.name'))
            ->toBe('Foundation graph parity');
    } finally {
        foreach ([$artifact, $artifact . '.meta.json'] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to remove a temporary InterMix artifact file.');
            }
        }
    }
})->with(RuntimeMode::cases());

it('keeps non-exportable config usable in development and reports the dynamic boundary', function (): void {
    $dynamicValue = static fn(): string => 'dynamic';
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository([
            'app' => ['env' => 'testing'],
            'runtime' => ['factory' => $dynamicValue],
        ]),
        RuntimeMode::Cli,
    );
    $builder = FoundationGraph::compose($context);
    $builder->releaseIdentity('foundation-dynamic-config-fixture');
    $development = $builder->build();
    $developmentConfig = \foundationService($development, ConfigRepository::class);

    $artifact = tempnam(sys_get_temp_dir(), 'foundation-graph-dynamic-');
    if ($artifact === false) {
        throw new RuntimeException('Unable to allocate a temporary InterMix artifact path.');
    }

    try {
        $builder->validate(strict: true);
        $report = $builder->compile($artifact);
        $production = $builder->production($artifact);

        expect($developmentConfig->get('runtime.factory'))->toBe($dynamicValue)
            ->and($report['skipped'])->toHaveKey(ConfigRepository::class)
            ->and($report['skipped'][ConfigRepository::class])->toBe('literal value requires the frozen dynamic fallback')
            ->and($report['skipped'])->not->toHaveKey(RuntimeMode::class)
            ->and(\foundationService($production, ConfigRepository::class))->toBe($developmentConfig);
    } finally {
        foreach ([$artifact, $artifact . '.meta.json'] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to remove a temporary InterMix artifact file.');
            }
        }
    }
});
