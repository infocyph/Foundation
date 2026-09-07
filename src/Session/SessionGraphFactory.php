<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Psr\Container\ContainerInterface;

final class SessionGraphFactory
{
    public static function config(ConfigRepository $config, PathManager $paths): SessionConfig
    {
        $session = SessionConfig::fromRepository($config, $paths->sessions());
        new SessionTopologyGuard($config)->assert($session);

        return $session;
    }

    public static function current(SessionManager $sessions): BrowserSession
    {
        return $sessions->current();
    }

    public static function lockedManager(
        SessionConfig $config,
        SessionStoreFactory $stores,
        CacheLayerFactory $cache,
        ContainerInterface $container,
    ): SessionManager {
        return new SessionManager(
            $config,
            static fn(): SessionStoreInterface => $stores->make(),
            static fn() => $cache->lock($config->lockStore),
            $container,
        );
    }

    public static function manager(
        SessionConfig $config,
        SessionStoreFactory $stores,
        ContainerInterface $container,
    ): SessionManager {
        return new SessionManager(
            $config,
            static fn(): SessionStoreInterface => $stores->make(),
            static fn() => null,
            $container,
        );
    }
}
