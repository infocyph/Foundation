<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\ProductionContainer;

/** One generated CLI, worker, or scheduler runtime reused for its process lifetime. */
final readonly class GeneratedRuntime
{
    /** @param array<string,mixed> $metadata */
    private function __construct(
        public RuntimeMode $runtimeMode,
        public ProductionContainer $container,
        public Application $application,
        public array $metadata,
    ) {}

    /**
     * @param array<string,mixed> $config
     * @param array<int|string,mixed> $capabilities Must match the compile-time topology.
     */
    public static function load(
        array $config,
        RuntimeMode $runtime,
        string $artifactPath,
        array $capabilities = [],
    ): self {
        $graph = new NonWebGraphFactory()->compose($config, $runtime, $capabilities);

        try {
            new NonWebProductionGraph()->prepare($graph->builder);
            $artifactPath = GeneratedRuntimeMetadata::resolvePath($graph->application, $artifactPath);
            $metadata = GeneratedRuntimeMetadata::read($artifactPath);
            GeneratedRuntimeMetadata::assertMatches($artifactPath, $metadata, $graph);
            $container = $graph->builder->production($artifactPath);
        } finally {
            $graph->application->container()->unset();
        }

        $runtimeConfig = $container->get(ConfigRepository::class);
        if (!$runtimeConfig instanceof ConfigRepository) {
            throw new \LogicException('Generated Foundation runtime did not produce a ConfigRepository.');
        }

        $application = new Application(
            config: $runtimeConfig,
            container: $container,
            providers: $graph->providers,
            bootstrapper: $graph->bootstrapper,
            runtimeMode: $runtime,
            bindDevelopmentCore: false,
        );
        $application->boot();

        return new self($runtime, $container, $application, $metadata);
    }
}
