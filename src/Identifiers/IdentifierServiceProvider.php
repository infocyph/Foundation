<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Identifiers;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class IdentifierServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(IdentifierManager::class, function () use ($container): IdentifierManager {
            $config = $container->get(ConfigRepository::class);
            $paths = $container->get(PathManager::class);

            if (!$config instanceof ConfigRepository) {
                throw new \RuntimeException('Identifier config service must resolve to ConfigRepository.');
            }

            if (!$paths instanceof PathManager) {
                throw new \RuntimeException('Identifier paths service must resolve to PathManager.');
            }

            return new IdentifierManager($config, $paths);
        }, LifetimeEnum::Singleton);

        $container->bind('foundation.ids', function () use ($container): IdentifierManager {
            $manager = $container->get(IdentifierManager::class);
            if (!$manager instanceof IdentifierManager) {
                throw new \RuntimeException('Foundation ids service must resolve to IdentifierManager.');
            }

            return $manager;
        }, LifetimeEnum::Singleton);

        $container->bind('foundation.uid', function () use ($container): IdentifierManager {
            $manager = $container->get(IdentifierManager::class);
            if (!$manager instanceof IdentifierManager) {
                throw new \RuntimeException('Foundation uid service must resolve to IdentifierManager.');
            }

            return $manager;
        }, LifetimeEnum::Singleton);
    }
}
