<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class SecurityServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(SecurityManager::class, fn() => new SecurityManager(
            config: $app->config(),
            container: $container,
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.security', fn() => $container->get(SecurityManager::class), LifetimeEnum::Singleton);
    }
}
