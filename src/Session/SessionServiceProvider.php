<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Session\Middleware\CsrfMiddleware;
use Infocyph\Foundation\Session\Middleware\SessionMiddleware;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Psr\Container\ContainerInterface;

final class SessionServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $session = is_array($context->config['session'] ?? null) ? $context->config['session'] : [];
        $driver = is_string($session['driver'] ?? null) ? $session['driver'] : 'file';
        $lock = is_array($session['lock'] ?? null) ? $session['lock'] : [];

        $this->registerCore($builder, $context);
        $this->registerStore($builder, $driver);
        $this->registerManager($builder, ($lock['enabled'] ?? false) === true);
        $this->registerMiddleware($builder);
    }

    private function registerCore(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $builder->singleton(SessionConfig::class, FactoryDefinition::staticFactory(
            SessionGraphFactory::class,
            'config',
            [new ServiceReference(ConfigRepository::class), new ServiceReference(PathManager::class)],
        ));
        $builder->scoped(
            SessionExecutionState::class,
            FactoryDefinition::construct(SessionExecutionState::class),
        );

        if ($context->runtimeMode !== RuntimeMode::Web) {
            return;
        }

        $builder->onScopeLeave(
            $context->runtimeMode->scopeName(),
            static function (string $scope, Container $container): void {
                unset($scope);
                $state = $container->get(SessionExecutionState::class);
                if ($state instanceof SessionExecutionState) {
                    $state->reset(false);
                }
            },
        );
    }

    private function registerManager(ContainerBuilder $builder, bool $lockEnabled): void
    {
        if ($lockEnabled) {
            if (!$builder->definitions()->has(CacheLayerFactory::class)) {
                throw new \LogicException('Session locking requires the Foundation cache capability.');
            }
            $builder->singleton(SessionManager::class, FactoryDefinition::staticFactory(
                SessionGraphFactory::class,
                'lockedManager',
                [
                    new ServiceReference(SessionConfig::class),
                    new ServiceReference(SessionStoreFactory::class),
                    new ServiceReference(CacheLayerFactory::class),
                    new ServiceReference(ContainerInterface::class),
                ],
            ));

            return;
        }

        $builder->singleton(SessionManager::class, FactoryDefinition::staticFactory(
            SessionGraphFactory::class,
            'manager',
            [
                new ServiceReference(SessionConfig::class),
                new ServiceReference(SessionStoreFactory::class),
                new ServiceReference(ContainerInterface::class),
            ],
        ));
    }

    private function registerMiddleware(ContainerBuilder $builder): void
    {
        $builder->singleton(SessionMiddleware::class, FactoryDefinition::construct(
            SessionMiddleware::class,
            [new ServiceReference(SessionManager::class), new ServiceReference(SessionConfig::class)],
        ));
        $builder->bind(
            BrowserSession::class,
            FactoryDefinition::staticFactory(
                SessionGraphFactory::class,
                'current',
                [new ServiceReference(SessionManager::class)],
            ),
            LifetimeEnum::Scoped,
        );
        $builder->singleton(CsrfMiddleware::class, FactoryDefinition::construct(
            CsrfMiddleware::class,
            [new ServiceReference(SessionConfig::class)],
        ));
        $builder->alias('foundation.session', SessionManager::class);
    }

    private function registerStore(ContainerBuilder $builder, string $driver): void
    {
        $storeArguments = [new ServiceReference(SessionConfig::class)];
        if ($driver === 'cache') {
            if (!$builder->definitions()->has(CacheManager::class)) {
                throw new \LogicException('Cache-backed sessions require the Foundation cache capability.');
            }
            $storeArguments[] = new ServiceReference(CacheManager::class);
        } elseif ($driver === 'database') {
            if (!$builder->definitions()->has(DBLayerFactory::class)) {
                throw new \LogicException('Database-backed sessions require the Foundation database capability.');
            }
            $storeArguments[] = null;
            $storeArguments[] = new ServiceReference(DBLayerFactory::class);
        }

        $builder->singleton(SessionStoreFactory::class, FactoryDefinition::construct(
            SessionStoreFactory::class,
            $storeArguments,
        ));

        if ($builder->definitions()->has(DBLayerFactory::class)) {
            $builder->singleton(SessionDatabaseSchema::class, FactoryDefinition::construct(
                SessionDatabaseSchema::class,
                [new ServiceReference(SessionConfig::class), new ServiceReference(DBLayerFactory::class)],
            ));
        }
    }
}
