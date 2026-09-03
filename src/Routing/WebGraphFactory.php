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
use Infocyph\Foundation\Container\FoundationGraph;

/** Recreates the canonical Foundation web graph without running route discovery. */
final class WebGraphFactory
{
    /** @param array<string, mixed> $config */
    public function compose(array $config): WebGraphComposition
    {
        $sourceConfig = new ConfigLoader()->load($config);
        $context = FoundationBuildContext::fromConfig($sourceConfig, RuntimeMode::Web);
        $builder = FoundationGraph::compose($context);
        $container = $builder->development();
        $runtimeConfig = $container->get(ConfigRepository::class);
        if (!$runtimeConfig instanceof ConfigRepository) {
            throw new \LogicException('Foundation web graph did not produce a ConfigRepository.');
        }

        $bootstrapper = new Bootstrapper();
        $providers = new ServiceRegistry();
        $application = new Application(
            config: $runtimeConfig,
            container: $container,
            providers: $providers,
            bootstrapper: $bootstrapper,
            runtimeMode: RuntimeMode::Web,
        );
        $bootstrapper->compose($builder, $context, $providers);

        return new WebGraphComposition($builder, $application, $context, $runtimeConfig);
    }
}
