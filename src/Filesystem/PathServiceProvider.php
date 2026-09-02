<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;

/** Core path services have no dependency on the optional filesystem module. */
final class PathServiceProvider extends ServiceProvider
{
    public function boot(Application $app): void
    {
        if ((bool) $app->config()->get('paths.auto_create_runtime_directories', false)) {
            $app->make(PathManager::class)->ensureRuntimeDirectories();
        }
    }

    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $app = is_array($context->config['app'] ?? null) ? $context->config['app'] : [];
        $paths = is_array($context->config['paths'] ?? null) ? $context->config['paths'] : [];

        $builder->singleton(
            PathManager::class,
            FactoryDefinition::construct(PathManager::class, [
                $this->basePath($app['base_path'] ?? null),
                $this->paths($paths),
            ]),
        );
        $builder->alias('foundation.paths', PathManager::class);
    }

    private function basePath(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return rtrim($value, DIRECTORY_SEPARATOR);
        }

        return getcwd() ?: dirname(__DIR__, 2);
    }

    /** @return array<string, string> */
    private function paths(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $key => $path) {
            if (is_string($key) && is_string($path) && $path !== '') {
                $paths[$key] = $path;
            }
        }

        return $paths;
    }
}
