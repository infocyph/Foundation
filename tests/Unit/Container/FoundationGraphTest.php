<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;

it('creates a fresh deterministic builder for each runtime composition', function (RuntimeMode $runtimeMode): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        $runtimeMode,
    );

    $first = FoundationGraph::compose($context);
    $second = FoundationGraph::compose($context);

    expect($first)->not->toBe($second)
        ->and($first->development())->not->toBe($second->development())
        ->and($first->development()->getRepository()->getAlias())->toBe($runtimeMode->containerAlias())
        ->and($second->development()->getRepository()->getAlias())->toBe($runtimeMode->containerAlias());
})->with(RuntimeMode::cases());

it('keeps core graph values equivalent in development and generated production', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository([
            'app' => [
                'env' => 'testing',
                'name' => 'Foundation graph parity',
                'container' => ['lazy_loading' => false],
            ],
        ]),
        RuntimeMode::Cli,
        ['cache' => true],
    );
    $builder = FoundationGraph::compose($context);
    $development = $builder->development();

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
            ->and($development->get(RuntimeMode::class))->toBe(RuntimeMode::Cli)
            ->and($production->get(RuntimeMode::class))->toBe(RuntimeMode::Cli)
            ->and($development->get(ConfigRepository::class)->all())
            ->toBe($production->get(ConfigRepository::class)->all())
            ->and($production->get(ConfigRepository::class)->get('app.name'))
            ->toBe('Foundation graph parity');
    } finally {
        foreach ([$artifact, $artifact . '.meta.json'] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to remove a temporary InterMix artifact file.');
            }
        }
    }
});
