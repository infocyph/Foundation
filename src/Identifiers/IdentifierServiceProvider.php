<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Identifiers;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class IdentifierServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $this->bindFactory($container, IdentifierManager::class, function () use ($container): IdentifierManager {
            $config = $container->get(ConfigRepository::class);
            if (!$config instanceof ConfigRepository) {
                throw new \RuntimeException('Identifier config service must resolve to ConfigRepository.');
            }

            return new IdentifierManager($config);
        }, LifetimeEnum::Singleton);

        $this->bindFactory($container, 'foundation.ids', function () use ($container): IdentifierManager {
            $manager = $container->get(IdentifierManager::class);
            if (!$manager instanceof IdentifierManager) {
                throw new \RuntimeException('Foundation ids service must resolve to IdentifierManager.');
            }

            return $manager;
        }, LifetimeEnum::Singleton);

        $this->bindFactory($container, 'foundation.uid', function () use ($container): IdentifierManager {
            $manager = $container->get(IdentifierManager::class);
            if (!$manager instanceof IdentifierManager) {
                throw new \RuntimeException('Foundation uid service must resolve to IdentifierManager.');
            }

            return $manager;
        }, LifetimeEnum::Singleton);
    }
}
