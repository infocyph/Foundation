<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Container;

use Infocyph\Foundation\Application\Application;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;

/** Development-only identities that must never leak into a generated production graph. */
final class DevelopmentRuntimeBindings
{
    public static function register(
        ContainerBuilder $builder,
        Application $application,
        Container $container,
    ): void {
        if (!$builder->definitions()->has(Application::class)) {
            $builder->value(Application::class, $application);
        }
        if (!$builder->definitions()->has(Container::class)) {
            $builder->value(Container::class, $container);
        }
    }
}
