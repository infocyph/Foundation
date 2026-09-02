<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthMfaRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        if (!class_exists(DB::class)) {
            throw new \LogicException(
                'Foundation database services require infocyph/dblayer; run "php infbyte module:install database".',
            );
        }

        $auth = is_array($context->config['auth'] ?? null) ? $context->config['auth'] : [];
        $oauth = is_array($auth['oauth'] ?? null) ? $auth['oauth'] : [];
        $oauthEnabled = ($oauth['enabled'] ?? false) === true;

        $builder->singleton(DatabaseConnectionResolver::class, FactoryDefinition::construct(
            DatabaseConnectionResolver::class,
            [new ServiceReference(ConfigRepository::class)],
        ));
        $builder->singleton(DBLayerFactory::class, FactoryDefinition::construct(DBLayerFactory::class, [
            new ServiceReference(DatabaseConnectionResolver::class),
            new ServiceReference(RuntimeContextTracker::class),
        ]));
        $builder->singleton(Connection::class, FactoryDefinition::staticFactory(
            DatabaseGraphFactory::class,
            'connection',
            [new ServiceReference(DBLayerFactory::class)],
        ));

        $builder->singleton(AuthTables::class, FactoryDefinition::construct(AuthTables::class));
        $builder->singleton(AuthSchema::class, FactoryDefinition::construct(
            AuthSchema::class,
            [new ServiceReference(AuthTables::class)],
        ));
        $builder->singleton(AuthMfaRevisionSchema::class, FactoryDefinition::construct(
            AuthMfaRevisionSchema::class,
            [new ServiceReference(AuthTables::class)],
        ));
        if ($oauthEnabled) {
            $builder->singleton(AuthOAuthRevisionSchema::class, FactoryDefinition::construct(
                AuthOAuthRevisionSchema::class,
                [new ServiceReference(AuthTables::class)],
            ));
        }
        $installerArguments = [
            new ServiceReference(DBLayerFactory::class),
            new ServiceReference(AuthSchema::class),
            new ServiceReference(AuthMfaRevisionSchema::class),
            new ServiceReference(AuthTables::class),
            $oauthEnabled ? new ServiceReference(AuthOAuthRevisionSchema::class) : null,
            $oauthEnabled,
        ];
        $builder->singleton(AuthSchemaInstaller::class, FactoryDefinition::construct(
            AuthSchemaInstaller::class,
            $installerArguments,
        ));

        $this->registerMigrationManager($builder);
        $builder->alias('foundation.db', Connection::class);
    }

    private function registerMigrationManager(ContainerBuilder $builder): void
    {
        $container = $builder->development();
        $hasCache = $builder->definitions()->has(CacheLayerFactory::class);

        $builder->bindFactory(DatabaseMigrationManager::class, static function () use ($container, $hasCache): DatabaseMigrationManager {
            $config = $container->get(ConfigRepository::class);
            $database = $container->get(DBLayerFactory::class);
            if (!$config instanceof ConfigRepository || !$database instanceof DBLayerFactory) {
                throw new \LogicException('Database migration graph dependencies are invalid.');
            }

            $cache = $hasCache ? $container->get(CacheLayerFactory::class) : null;
            if ($cache !== null && !$cache instanceof CacheLayerFactory) {
                throw new \LogicException('CacheLayerFactory binding is invalid.');
            }

            return new DatabaseMigrationManager(
                config: $config,
                factory: $database,
                resolver: static function (string $service) use ($container): object {
                    $resolved = $container->make($service);
                    if (!is_object($resolved)) {
                        throw new \UnexpectedValueException(sprintf(
                            'Database definition "%s" did not resolve to an object.',
                            $service,
                        ));
                    }

                    return $resolved;
                },
                cache: $cache,
            );
        });
    }
}
