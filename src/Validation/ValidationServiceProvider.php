<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\ReqShield\Contracts\DatabaseProvider;
use Infocyph\ReqShield\Validator;

final class ValidationServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        if (!class_exists(Validator::class)) {
            throw new \LogicException(
                'Foundation validation services require infocyph/reqshield; run "php infbyte module:install validation".',
            );
        }

        $validation = is_array($context->config['validation'] ?? null) ? $context->config['validation'] : [];
        $connection = $validation['database_connection'] ?? null;
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $builder->singleton(ValidationSchemaRegistry::class, FactoryDefinition::construct(
            ValidationSchemaRegistry::class,
            [new ServiceReference(ConfigRepository::class), AuthRequestSchemas::all()],
        ));
        $builder->singleton(ReqShieldDatabaseProvider::class, FactoryDefinition::staticFactory(
            ValidationGraphFactory::class,
            'databaseProvider',
            [new ServiceReference(DBLayerFactory::class), $connection],
        ));
        $builder->alias(DatabaseProvider::class, ReqShieldDatabaseProvider::class);
        $builder->singleton(ValidatorFactory::class, FactoryDefinition::construct(
            ValidatorFactory::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(ValidationSchemaRegistry::class),
                new ServiceReference(DatabaseProvider::class),
            ],
        ));
        $builder->alias('foundation.validator', ValidatorFactory::class);
    }
}
