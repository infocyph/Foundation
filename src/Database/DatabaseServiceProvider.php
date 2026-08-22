<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        if (!class_exists(DB::class)) {
            throw new \LogicException(
                'Foundation database services require infocyph/dblayer; run "php infbyte module:install db".',
            );
        }

        $container = $app->container();

        $this->bindFactory($container, DatabaseConnectionResolver::class, fn() => new DatabaseConnectionResolver(
            $app->config(),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, DBLayerFactory::class, fn() => new DBLayerFactory(
            $app->make(DatabaseConnectionResolver::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory(
            $container,
            Connection::class,
            fn() => $app->make(DBLayerFactory::class)->connection(),
            LifetimeEnum::Singleton,
        );

        $container->bind(AuthTables::class, new AuthTables(), LifetimeEnum::Singleton);
        $this->bindFactory($container, AuthSchema::class, fn() => new AuthSchema(
            $app->make(AuthTables::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, AuthSchemaInstaller::class, fn() => new AuthSchemaInstaller(
            $app->make(DBLayerFactory::class),
            $app->make(AuthSchema::class),
            $app->make(AuthTables::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, DatabaseMigrationManager::class, fn() => new DatabaseMigrationManager(
            $app,
            $app->make(DBLayerFactory::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, DatabaseManager::class, fn() => new DatabaseManager(
            config: $app->config(),
            factory: $app->make(DBLayerFactory::class),
            authSchemaInstaller: $app->make(AuthSchemaInstaller::class),
            migrations: $app->make(DatabaseMigrationManager::class),
            contexts: $app->make(RuntimeContextTracker::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, 'foundation.db', fn() => $container->get(DatabaseManager::class), LifetimeEnum::Singleton);
    }
}
