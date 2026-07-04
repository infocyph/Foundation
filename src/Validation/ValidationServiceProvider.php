<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class ValidationServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(ValidationSchemaRegistry::class, fn() => new ValidationSchemaRegistry(
            config: $app->config(),
            baseSchemas: AuthRequestSchemas::all(),
        ), LifetimeEnum::Singleton);

        $container->bind(FoundationValidator::class, fn() => new FoundationValidator(
            config: $app->config(),
            schemas: $app->make(ValidationSchemaRegistry::class),
        ), LifetimeEnum::Singleton);

        $container->bind(ValidationManager::class, fn() => new ValidationManager(
            config: $app->config(),
            validator: $app->make(FoundationValidator::class),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.validator', fn() => $container->get(ValidationManager::class), LifetimeEnum::Singleton);
    }
}
