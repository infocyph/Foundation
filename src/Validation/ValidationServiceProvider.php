<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\ReqShield\Contracts\DatabaseProvider;
use Infocyph\ReqShield\Validator;

final class ValidationServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        if (!class_exists(Validator::class)) {
            throw new \LogicException(
                'Foundation validation services require infocyph/reqshield; run "php infbyte module:install validation".',
            );
        }

        $container = $app->container();

        $this->bindFactory($container, ValidationSchemaRegistry::class, fn() => new ValidationSchemaRegistry(
            config: $app->config(),
            baseSchemas: AuthRequestSchemas::all(),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, ReqShieldDatabaseProvider::class, fn() => new ReqShieldDatabaseProvider(
            connection: fn() => $app->make(DBLayerFactory::class)->connection($this->databaseConnection($app)),
        ), LifetimeEnum::Singleton);
        $this->bindFactory(
            $container,
            DatabaseProvider::class,
            fn() => $container->get(ReqShieldDatabaseProvider::class),
            LifetimeEnum::Singleton,
        );

        $this->bindFactory($container, ValidatorFactory::class, fn() => new ValidatorFactory(
            config: $app->config(),
            schemas: $container->get(ValidationSchemaRegistry::class),
            database: $container->get(DatabaseProvider::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory(
            $container,
            'foundation.validator',
            fn() => $container->get(ValidatorFactory::class),
            LifetimeEnum::Singleton,
        );
    }

    private function databaseConnection(Application $app): ?string
    {
        $connection = $app->config()->get('validation.database_connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
