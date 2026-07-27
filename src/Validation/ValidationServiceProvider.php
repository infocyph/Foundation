<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
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
            database: fn(): DatabaseManager => $app->make(DatabaseManager::class),
            connection: $this->databaseConnection($app),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, FoundationValidator::class, fn() => new FoundationValidator(
            config: $app->config(),
            database: $app->make(ReqShieldDatabaseProvider::class),
            schemas: $app->make(ValidationSchemaRegistry::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, ValidationManager::class, fn() => new ValidationManager(
            config: $app->config(),
            validator: $app->make(FoundationValidator::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, 'foundation.validator', fn() => $container->get(ValidationManager::class), LifetimeEnum::Singleton);
    }

    private function databaseConnection(Application $app): ?string
    {
        $connection = $app->config()->get('validation.database_connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
