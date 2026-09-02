<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Container;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\ContainerBuilder;

final class FoundationGraph
{
    public static function compose(
        ConfigRepository $config,
        FoundationBuildContext $context,
    ): ContainerBuilder {
        $builder = ContainerBuilder::create($context->runtimeMode->containerAlias());

        if ($context->environment !== null) {
            $builder->setEnvironment($context->environment);
        }

        if ($context->lazyLoading) {
            $builder->enableLazyLoading();
        }

        if ($context->debugTracing) {
            $builder->options()->enableDebugTracing(true, $context->debugTraceLevel);
        }

        $builder->value(ConfigRepository::class, $config);
        $builder->value(RuntimeMode::class, $context->runtimeMode);

        return $builder;
    }
}
