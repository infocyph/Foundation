<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Container;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Command\CommandContext;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\CommandDescriptor;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\ParsedInput;
use Infocyph\Foundation\Config\ConfigExportValidator;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Runtime\ExecutionScope;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Infocyph\Foundation\Scheduling\ScheduledCommand;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Request\Request;
use Psr\Container\ContainerInterface;

final class FoundationGraph
{
    public static function compose(FoundationBuildContext $context): ContainerBuilder
    {
        return self::contributeTo(self::createBuilder($context), $context);
    }

    public static function contributeTo(ContainerBuilder $builder, FoundationBuildContext $context): ContainerBuilder
    {
        self::registerConfigRepository($builder, $context);
        $builder->factory(RuntimeMode::class, FactoryDefinition::staticFactory(
            RuntimeMode::class,
            'from',
            [$context->runtimeMode->value],
        ));
        $builder->factory(
            RuntimeExecutionState::class,
            FactoryDefinition::construct(RuntimeExecutionState::class),
            LifetimeEnum::Scoped,
        );
        $builder->factory(ExecutionScope::class, FactoryDefinition::construct(
            ExecutionScope::class,
            [
                new ServiceReference(ContainerInterface::class),
                new ServiceReference(RuntimeMode::class),
            ],
        ));

        if ($context->runtimeMode === RuntimeMode::Web) {
            $builder->input(Request::class);
        } else {
            $builder->input(ExecutionId::class);
        }
        if ($context->runtimeMode === RuntimeMode::Scheduler) {
            $builder->input(ScheduledCommand::class);
        }
        if ($context->runtimeMode === RuntimeMode::Cli) {
            foreach ([
                ParsedInput::class,
                CommandIO::class,
                CommandContext::class,
                CommandDescriptor::class,
                CommandDefinition::class,
            ] as $id) {
                $builder->input($id);
            }
        }

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
            $builder->enableDebugTracing(true, $context->debugTraceLevel);
        }

        return $builder;
    }

    private static function registerConfigRepository(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        if (ConfigExportValidator::isExportable($context->config)) {
            $builder->factory(ConfigRepository::class, FactoryDefinition::construct(
                ConfigRepository::class,
                [$context->config, $context->compiledConfig],
            ));

            return;
        }

        $builder->value(ConfigRepository::class, new ConfigRepository($context->config, $context->compiledConfig));
    }
}
