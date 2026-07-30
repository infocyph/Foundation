<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Session\Middleware\CsrfMiddleware;
use Infocyph\Foundation\Session\Middleware\SessionMiddleware;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class SessionServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $this->bindFactory($container, SessionConfig::class, fn() => SessionConfig::fromRepository(
            $app->config(),
            $app->sessionsPath(),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, SessionStoreFactory::class, fn() => new SessionStoreFactory(
            $app,
            $app->make(SessionConfig::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, SessionDatabaseSchema::class, fn() => new SessionDatabaseSchema(
            $app->make(SessionConfig::class),
            fn() => $app->db(),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, SessionManager::class, fn() => new SessionManager(
            $app->make(SessionConfig::class),
            fn(): SessionStoreInterface => $app->make(SessionStoreFactory::class)->make(),
            function () use ($app): ?LockProviderInterface {
                $config = $app->make(SessionConfig::class);
                if (!$config->lockEnabled) {
                    return null;
                }

                return $app->make(CacheLayerFactory::class)->lock($config->lockStore);
            },
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, SessionMiddleware::class, fn() => new SessionMiddleware(
            $app->make(SessionManager::class),
            $app->make(SessionConfig::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, BrowserSession::class, fn() => $app->make(SessionManager::class)->current(), LifetimeEnum::Scoped);
        $this->bindFactory($container, CsrfMiddleware::class, fn() => new CsrfMiddleware(
            $app->make(SessionConfig::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, 'foundation.session', fn() => $container->get(SessionManager::class), LifetimeEnum::Singleton);
    }
}
