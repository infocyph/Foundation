<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Data;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class DataServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(DataManager::class, function () use ($container): DataManager {
            $paths = $container->get(PathManager::class);
            if (!$paths instanceof PathManager) {
                throw new \RuntimeException('Data paths service must resolve to PathManager.');
            }

            return new DataManager($paths);
        }, LifetimeEnum::Singleton);

        $container->bind('foundation.data', function () use ($container): DataManager {
            $manager = $container->get(DataManager::class);
            if (!$manager instanceof DataManager) {
                throw new \RuntimeException('Foundation data service must resolve to DataManager.');
            }

            return $manager;
        }, LifetimeEnum::Singleton);
    }
}
