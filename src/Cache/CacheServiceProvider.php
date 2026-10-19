<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Interop\CacheLayer\AtomicCounterAdapter;
use Infocyph\Webrick\Middleware\Throttle\AtomicCounterInterface as WebrickAtomicCounterInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

final class CacheServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $hasDatabase = $context->permitsCapability('database') && class_exists(Connection::class);
        $builder->factory(CacheLayerFactory::class, FactoryDefinition::staticFactory(
            CacheGraphFactory::class,
            $hasDatabase ? 'layerFactory' : 'layerFactoryWithoutDatabase',
            $hasDatabase
                ? [
                    new ServiceReference(ConfigRepository::class),
                    new ServiceReference(PathManager::class),
                    new ServiceReference(ContainerInterface::class),
                ]
                : [
                    new ServiceReference(ConfigRepository::class),
                    new ServiceReference(PathManager::class),
                ],
        ));
        $builder->factory(CacheManager::class, FactoryDefinition::staticFactory(
            CacheGraphFactory::class,
            $hasDatabase ? 'manager' : 'managerWithoutDatabase',
            $hasDatabase
                ? [
                    new ServiceReference(CacheLayerFactory::class),
                    new ServiceReference(ContainerInterface::class),
                    new ServiceReference(ConfigRepository::class),
                    new ServiceReference(PathManager::class),
                ]
                : [new ServiceReference(CacheLayerFactory::class)],
        ));
        $builder->factory(CacheInterface::class, FactoryDefinition::staticFactory(
            CacheGraphFactory::class,
            'store',
            [new ServiceReference(CacheManager::class)],
        ));

        $builder->alias(Cache::class, CacheInterface::class);
        $builder->alias(AuthenticationStateCacheInterface::class, CacheInterface::class);
        $builder->alias(SimpleCacheInterface::class, CacheInterface::class);
        $builder->alias(CacheItemPoolInterface::class, CacheInterface::class);
        $builder->factory(LockProviderInterface::class, FactoryDefinition::staticFactory(
            CacheGraphFactory::class,
            'lock',
            [new ServiceReference(CacheManager::class)],
        ));

        // CacheLayer memoizers are explicit process-local utilities. Foundation
        // deliberately does not promote their global instance() state into the
        // application graph where it could be mistaken for execution-local state.

        $cache = is_array($context->config['cache'] ?? null) ? $context->config['cache'] : [];
        $counter = $cache['default_counter'] ?? null;
        if (is_string($counter) && $counter !== '') {
            $builder->factory(AtomicCounterStoreInterface::class, FactoryDefinition::staticFactory(
                CacheGraphFactory::class,
                'counters',
                [new ServiceReference(CacheLayerFactory::class), $counter],
            ));
            if ($context->runtimeMode === RuntimeMode::Web) {
                $builder->factory(WebrickAtomicCounterInterface::class, FactoryDefinition::construct(
                    AtomicCounterAdapter::class,
                    [new ServiceReference(AtomicCounterStoreInterface::class)],
                ));
            }
        }

        $builder->alias('foundation.cache', CacheInterface::class);
    }
}
