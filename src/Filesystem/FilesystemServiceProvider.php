<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class FilesystemServiceProvider extends ServiceProvider
{
    public function boot(Application $app): void
    {
        if ((bool) $app->config()->get('paths.auto_create_runtime_directories', false)) {
            $app->make(FilesystemManager::class)->ensureRuntimeDirectories();
        }
    }

    public function register(Application $app): void
    {
        $container = $app->container();
        $config = $app->config();

        $container->bind(PathManager::class, fn() => new PathManager(
            basePath: $this->basePath($config->get('app.base_path')),
            paths: $this->paths($config->get('paths', [])),
        ), LifetimeEnum::Singleton);

        $container->bind(FilesystemManager::class, function () use ($app, $container): FilesystemManager {
            $paths = $container->get(PathManager::class);
            if (!$paths instanceof PathManager) {
                throw new \RuntimeException('Filesystem paths service must resolve to PathManager.');
            }

            return new FilesystemManager(
                config: $app->config(),
                paths: $paths,
            );
        }, LifetimeEnum::Singleton);

        $container->bind('foundation.files', fn() => $container->get(FilesystemManager::class), LifetimeEnum::Singleton);
        $container->bind('foundation.filesystem', fn() => $container->get(FilesystemManager::class), LifetimeEnum::Singleton);
        $container->bind('foundation.paths', fn() => $container->get(PathManager::class), LifetimeEnum::Singleton);
    }

    private function basePath(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return rtrim($value, DIRECTORY_SEPARATOR);
        }

        return getcwd() ?: dirname(__DIR__, 2);
    }

    /**
     * @return array<string, string>
     */
    private function paths(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $paths = [];

        foreach ($value as $key => $path) {
            if (!is_string($key) || !is_string($path) || $path === '') {
                continue;
            }

            $paths[$key] = $path;
        }

        return $paths;
    }
}
