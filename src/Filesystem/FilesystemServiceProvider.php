<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class FilesystemServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(PathManager::class, fn() => new PathManager(
            config: $app->config(),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.paths', fn() => $container->get(PathManager::class), LifetimeEnum::Singleton);
    }
}
