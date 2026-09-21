<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;

final class Phase9DiProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);

        self::definitions($builder);
    }

    public static function definitions(ContainerBuilder $builder): void
    {
        $builder->bind(
            Phase9DiLeaf::class,
            FactoryDefinition::construct(Phase9DiLeaf::class, ['phase-9']),
            LifetimeEnum::Singleton,
        );
        $builder->bind(
            Phase9DiNode::class,
            FactoryDefinition::construct(Phase9DiNode::class, [new ServiceReference(Phase9DiLeaf::class)]),
            LifetimeEnum::Transient,
        );
        $builder->bind(
            Phase9DiScopedProbe::class,
            FactoryDefinition::construct(Phase9DiScopedProbe::class),
            LifetimeEnum::Scoped,
        );
    }
}
