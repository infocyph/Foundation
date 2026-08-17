<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;

abstract class ServiceProvider implements ServiceProviderInterface
{
    public function boot(Application $app): void {}

    /**
     * Register an explicit factory without requiring closure autowiring.
     *
     * @param Container $container Target application container.
     * @param string $id Service identifier.
     * @param Closure $factory Reflection-free service factory.
     * @param LifetimeEnum $lifetime Service lifetime.
     * @param array<int, string> $tags
     */
    final protected function bindFactory(
        Container $container,
        string $id,
        Closure $factory,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): void {
        $binding = $container->factory($id, $factory);

        match ($lifetime) {
            LifetimeEnum::Singleton => $binding->singleton($tags),
            LifetimeEnum::Scoped => $binding->scoped($tags),
            LifetimeEnum::Transient => $binding->transient($tags),
        };
    }

    /**
     * Register an immutable construction recipe that InterMix may compile.
     *
     * @param Container $container Target application container.
     * @param string $id Service identifier.
     * @param class-string $class
     * @param list<scalar|array<array-key, mixed>|ServiceReference|null> $arguments
     * @param LifetimeEnum $lifetime Service lifetime.
     * @param array<int, string> $tags
     */
    final protected function bindRecipe(
        Container $container,
        string $id,
        string $class,
        array $arguments = [],
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): void {
        $container->bind(
            $id,
            FactoryDefinition::construct($class, $arguments),
            $lifetime,
            $tags,
        );
    }

    /**
     * Determine whether an entry was explicitly registered rather than merely
     * being autowireable by class name.
     */
    final protected function hasExplicitBinding(Container $container, string $id): bool
    {
        $repository = $container->getRepository();

        return $repository->hasFunctionReference($id)
            || $repository->hasClosureResource($id)
            || $repository->hasResolved($id)
            || $repository->hasResolvedDefinition($id);
    }
}
