<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\DevelopmentRuntimeBindings;
use Infocyph\Foundation\Container\FoundationGraph;

/** Recreates the canonical Foundation web graph without running route discovery. */
final class WebGraphFactory
{
    /**
     * Passing null preserves development installed-package discovery. Passing an
     * array, including [], makes compiled/runtime capability topology explicit.
     *
     * @param array<string, mixed>|ConfigRepository $config
     * @param array<int|string, mixed>|null $capabilities
     */
    public function compose(array|ConfigRepository $config, ?array $capabilities = null): WebGraphComposition
    {
        $sourceConfig = $config instanceof ConfigRepository
            ? $config
            : new ConfigLoader()->load($config);
        $context = FoundationBuildContext::fromConfig($sourceConfig, RuntimeMode::Web, $capabilities);
        $builder = FoundationGraph::compose($context);
        $bootstrapper = new Bootstrapper();
        $providers = new ServiceRegistry();
        $bootstrapper->compose($builder, $context, $providers);

        $bindings = DevelopmentRuntimeBindings::register($builder);
        $container = $builder->development();
        $runtimeConfig = $container->get(ConfigRepository::class);
        if (!$runtimeConfig instanceof ConfigRepository) {
            throw new \LogicException('Foundation web graph did not produce a ConfigRepository.');
        }

        $application = new Application(
            config: $runtimeConfig,
            container: $container,
            providers: $providers,
            bootstrapper: $bootstrapper,
            runtimeMode: RuntimeMode::Web,
        );
        $bindings->attach($application);

        return new WebGraphComposition($builder, $application, $context, $runtimeConfig);
    }
}
