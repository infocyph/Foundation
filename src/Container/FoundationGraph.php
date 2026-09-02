<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Container;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\RuntimeModeFactory;
use Infocyph\Foundation\Config\ConfigExportValidator;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;

final class FoundationGraph
{
    public static function compose(FoundationBuildContext $context): ContainerBuilder
    {
        return self::contributeTo(self::createBuilder($context), $context);
    }

    public static function contributeTo(
        ContainerBuilder $builder,
        FoundationBuildContext $context,
    ): ContainerBuilder {
        self::registerConfigRepository($builder, $context);
        $builder->singleton(
            RuntimeMode::class,
            FactoryDefinition::staticFactory(
                RuntimeModeFactory::class,
                'from',
                [$context->runtimeMode->value],
            ),
        );
        $builder->singleton(
            RuntimeContextTracker::class,
            FactoryDefinition::construct(RuntimeContextTracker::class),
        );

        return $builder;
    }

    public static function createBuilder(FoundationBuildContext $context): ContainerBuilder
    {
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

        return $builder;
    }

    private static function registerConfigRepository(
        ContainerBuilder $builder,
        FoundationBuildContext $context,
    ): void {
        if (ConfigExportValidator::isExportable($context->config)) {
            $builder->singleton(
                ConfigRepository::class,
                FactoryDefinition::construct(
                    ConfigRepository::class,
                    [$context->config, $context->compiledConfig],
                ),
            );

            return;
        }

        $builder->value(
            ConfigRepository::class,
            new ConfigRepository($context->config, $context->compiledConfig),
        );
    }
}
