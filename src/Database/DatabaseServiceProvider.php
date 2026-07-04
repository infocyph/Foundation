<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(DatabaseConnectionResolver::class, fn() => new DatabaseConnectionResolver(
            $app->config(),
        ), LifetimeEnum::Singleton);

        $container->bind(DBLayerFactory::class, fn() => new DBLayerFactory(
            $app->make(DatabaseConnectionResolver::class),
        ), LifetimeEnum::Singleton);

        $container->bind(AuthTables::class, new AuthTables(), LifetimeEnum::Singleton);
        $container->bind(AuthSchema::class, fn() => new AuthSchema(
            $app->make(AuthTables::class),
        ), LifetimeEnum::Singleton);
        $container->bind(AuthSchemaInstaller::class, fn() => new AuthSchemaInstaller(
            $app->make(DBLayerFactory::class),
            $app->make(AuthSchema::class),
            $app->make(AuthTables::class),
        ), LifetimeEnum::Singleton);

        $container->bind(DatabaseManager::class, fn() => new DatabaseManager(
            config: $app->config(),
            factory: $app->make(DBLayerFactory::class),
            authSchemaInstaller: $app->make(AuthSchemaInstaller::class),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.db', fn() => $container->get(DatabaseManager::class), LifetimeEnum::Singleton);
    }
}
