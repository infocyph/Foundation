<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(NotificationManager::class, fn() => new NotificationManager(
            config: $app->config(),
            container: $container,
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.notifications', fn() => $container->get(NotificationManager::class), LifetimeEnum::Singleton);
    }
}
