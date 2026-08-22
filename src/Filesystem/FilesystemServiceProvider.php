<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Pathwise\PathwiseFacade;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use League\Flysystem\FilesystemOperator;

final class FilesystemServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        if (!class_exists(PathwiseFacade::class)) {
            throw new \LogicException(
                'Foundation filesystem services require infocyph/pathwise; run "php infbyte module:install filesystem".',
            );
        }

        $container = $app->container();
        $paths = $app->make(PathManager::class);

        $this->bindFactory($container, StorageRegistry::class, fn() => new StorageRegistry(
            config: $app->config(),
            paths: $paths,
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, FilesystemTransferFactory::class, fn() => new FilesystemTransferFactory(
            config: $app->config(),
            paths: $paths,
            storage: $app->make(StorageRegistry::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, FilesystemOperator::class, fn() => $app->make(StorageRegistry::class)->disk(), LifetimeEnum::Singleton);
        $this->bindFactory($container, UploadProcessor::class, fn() => $app->make(FilesystemTransferFactory::class)->upload(), LifetimeEnum::Transient);
        $this->bindFactory($container, DownloadProcessor::class, fn() => $app->make(FilesystemTransferFactory::class)->download(), LifetimeEnum::Transient);

        $this->bindFactory($container, FilesystemUploadRequestHandler::class, fn() => new FilesystemUploadRequestHandler(
            $app->make(FilesystemTransferFactory::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, FilesystemResponseFactory::class, fn() => new FilesystemResponseFactory(
            transfers: $app->make(FilesystemTransferFactory::class),
            storage: $app->make(StorageRegistry::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, StorageLinkManager::class, fn() => new StorageLinkManager(
            config: $app->config(),
            paths: $paths,
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, 'foundation.files', fn() => $app->make(StorageRegistry::class), LifetimeEnum::Singleton);
        $this->bindFactory($container, 'foundation.filesystem', fn() => $app->make(StorageRegistry::class), LifetimeEnum::Singleton);
    }
}
