<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;

abstract class ServiceProvider implements ServiceProviderInterface
{
    public function boot(Application $app): void {}

    final protected function application(ContainerBuilder $builder): Application
    {
        $container = $builder->development();
        if (!$container->definitions()->has(Application::class)) {
            throw new \LogicException('Foundation Application must exist before provider contribution.');
        }

        $app = $container->get(Application::class);
        if (!$app instanceof Application) {
            throw new \LogicException('Foundation Application binding is invalid during provider contribution.');
        }

        return $app;
    }

    /** @param array<int, string> $tags */
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
     * @param class-string $class
     * @param list<scalar|array<array-key, mixed>|ServiceReference|null> $arguments
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

    final protected function hasExplicitBinding(Container $container, string $id): bool
    {
        return $container->definitions()->has($id);
    }
}
