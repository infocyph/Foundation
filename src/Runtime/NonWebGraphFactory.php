<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\DevelopmentRuntimeBindings;
use Infocyph\Foundation\Container\FoundationGraph;

/** Recreates one deterministic non-web graph from normalized build inputs. */
final class NonWebGraphFactory
{
    /**
     * @param array<string, mixed>|ConfigRepository $config
     * @param array<int|string, mixed> $capabilities
     */
    public function compose(
        array|ConfigRepository $config,
        RuntimeMode $runtime,
        array $capabilities = [],
    ): NonWebGraphComposition {
        if ($runtime === RuntimeMode::Web) {
            throw new \InvalidArgumentException('Web runtime compilation must use the coordinated Webrick release path.');
        }

        $sourceConfig = $config instanceof ConfigRepository
            ? $config
            : new ConfigLoader()->load($config);
        $context = FoundationBuildContext::fromConfig($sourceConfig, $runtime, $capabilities);
        $builder = FoundationGraph::compose($context);
        $bootstrapper = new Bootstrapper();
        $providers = new ServiceRegistry();
        $bootstrapper->compose($builder, $context, $providers);

        $bindings = DevelopmentRuntimeBindings::register($builder);
        $container = $builder->development();
        $runtimeConfig = $container->get(ConfigRepository::class);
        if (!$runtimeConfig instanceof ConfigRepository) {
            throw new \LogicException('Foundation non-web graph did not produce a ConfigRepository.');
        }

        $application = new Application(
            config: $runtimeConfig,
            container: $container,
            providers: $providers,
            bootstrapper: $bootstrapper,
            runtimeMode: $runtime,
        );
        $bindings->attach($application);

        return new NonWebGraphComposition(
            builder: $builder,
            application: $application,
            context: $context,
            config: $runtimeConfig,
            providers: $providers,
            bootstrapper: $bootstrapper,
        );
    }
}
