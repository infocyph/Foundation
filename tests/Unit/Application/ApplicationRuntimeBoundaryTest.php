<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;

it('keeps generated production resolution behind the runtime-neutral application facade', function (): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository([
            'app' => [
                'env' => 'production',
                'container' => ['lazy_loading' => false],
            ],
        ]),
        RuntimeMode::Cli,
    );
    $builder = FoundationGraph::compose($context);
    $artifact = tempnam(sys_get_temp_dir(), 'foundation-app-runtime-');
    if ($artifact === false) {
        throw new RuntimeException('Unable to allocate a temporary InterMix artifact path.');
    }

    try {
        $builder->validate(strict: true);
        $builder->compile($artifact);
        $runtime = $builder->production($artifact);
        $config = $runtime->get(ConfigRepository::class);
        if (!$config instanceof ConfigRepository) {
            throw new RuntimeException('Generated runtime did not resolve ConfigRepository.');
        }

        $app = new Application(
            config: $config,
            container: $runtime,
            providers: new ServiceRegistry(),
            bootstrapper: new Bootstrapper(),
            runtimeMode: RuntimeMode::Cli,
        );

        expect($app->runtime())->toBe($runtime)
            ->and($app->make(RuntimeMode::class))->toBe(RuntimeMode::Cli)
            ->and($app->has(RuntimeMode::class))->toBeTrue();

        expect(fn() => $app->container())
            ->toThrow(LogicException::class, 'mutable InterMix development container is unavailable');
    } finally {
        foreach ([$artifact, $artifact . '.meta.json'] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to remove a temporary InterMix artifact file.');
            }
        }
    }
});
