<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

abstract class ServiceProvider implements ServiceProviderInterface
{
    public function boot(Application $app): void {}

    /**
     * Register an explicit factory without requiring closure autowiring.
     *
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
}
