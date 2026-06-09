<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class RoutingServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(RouterManager::class, fn() => new RouterManager(
            config: $app->config(),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.router', fn() => $container->get(RouterManager::class), LifetimeEnum::Singleton);
    }
}
