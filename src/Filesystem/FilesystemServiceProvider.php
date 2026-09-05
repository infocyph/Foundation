<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Pathwise\PathwiseFacade;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use League\Flysystem\FilesystemOperator;

final class FilesystemServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);

        if (!class_exists(PathwiseFacade::class)) {
            throw new \LogicException(
                'Foundation filesystem services require infocyph/pathwise; run "php infbyte module:install filesystem".',
            );
        }

        $builder->singleton(StorageRegistry::class, FactoryDefinition::construct(StorageRegistry::class, [
            new ServiceReference(ConfigRepository::class),
            new ServiceReference(PathManager::class),
        ]));
        $builder->singleton(FilesystemTransferFactory::class, FactoryDefinition::construct(FilesystemTransferFactory::class, [
            new ServiceReference(ConfigRepository::class),
            new ServiceReference(PathManager::class),
            new ServiceReference(StorageRegistry::class),
        ]));
        $builder->singleton(FilesystemOperator::class, FactoryDefinition::staticFactory(
            FilesystemGraphFactory::class,
            'operator',
            [new ServiceReference(StorageRegistry::class)],
        ));
        $builder->bind(
            UploadProcessor::class,
            FactoryDefinition::staticFactory(
                FilesystemGraphFactory::class,
                'upload',
                [new ServiceReference(FilesystemTransferFactory::class)],
            ),
            LifetimeEnum::Transient,
        );
        $builder->bind(
            DownloadProcessor::class,
            FactoryDefinition::staticFactory(
                FilesystemGraphFactory::class,
                'download',
                [new ServiceReference(FilesystemTransferFactory::class)],
            ),
            LifetimeEnum::Transient,
        );
        $builder->singleton(FilesystemUploadRequestHandler::class, FactoryDefinition::construct(
            FilesystemUploadRequestHandler::class,
            [new ServiceReference(FilesystemTransferFactory::class)],
        ));
        $builder->singleton(FilesystemResponseFactory::class, FactoryDefinition::construct(
            FilesystemResponseFactory::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(FilesystemTransferFactory::class),
                new ServiceReference(StorageRegistry::class),
            ],
        ));
        $builder->singleton(StorageLinkManager::class, FactoryDefinition::construct(StorageLinkManager::class, [
            new ServiceReference(ConfigRepository::class),
            new ServiceReference(PathManager::class),
        ]));

        $builder->alias('foundation.files', StorageRegistry::class);
        $builder->alias('foundation.filesystem', StorageRegistry::class);
    }
}
