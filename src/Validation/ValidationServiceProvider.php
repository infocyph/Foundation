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

        $container->bind(ValidationManager::class, fn() => new ValidationManager(
            config: $app->config(),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.validator', fn() => $container->get(ValidationManager::class), LifetimeEnum::Singleton);
    }
}
