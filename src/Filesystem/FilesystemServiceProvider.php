<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Pathwise\PathwiseFacade;

final class FilesystemServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $this->bindFactory($container, FilesystemManager::class, function () use ($app, $container): FilesystemManager {
            if (!class_exists(PathwiseFacade::class)) {
                throw new \LogicException(
                    'Foundation filesystem services require infocyph/pathwise; run "php infbyte module:install filesystem".',
                );
            }

            $paths = $container->get(PathManager::class);
            if (!$paths instanceof PathManager) {
                throw new \RuntimeException('Filesystem paths service must resolve to PathManager.');
            }

            return new FilesystemManager(
                config: $app->config(),
                paths: $paths,
            );
        }, LifetimeEnum::Singleton);

        $this->bindFactory($container, 'foundation.files', fn() => $container->get(FilesystemManager::class), LifetimeEnum::Singleton);
        $this->bindFactory($container, 'foundation.filesystem', fn() => $container->get(FilesystemManager::class), LifetimeEnum::Singleton);
    }
}
