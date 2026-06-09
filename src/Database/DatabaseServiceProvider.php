<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(DatabaseManager::class, fn() => new DatabaseManager(
            config: $app->config(),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.db', fn() => $container->get(DatabaseManager::class), LifetimeEnum::Singleton);
    }
}
