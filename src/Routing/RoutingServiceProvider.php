<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class RoutingServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(WebrickMiddlewareFactory::class, fn() => new WebrickMiddlewareFactory(
            app: $app,
            config: $app->config(),
        ), LifetimeEnum::Singleton);
        $container->bind(WebrickRouterFactory::class, fn() => new WebrickRouterFactory(
            $app->config(),
            $app->make(WebrickMiddlewareFactory::class),
            $container,
        ), LifetimeEnum::Singleton);

        $container->bind(RouteMiddlewareRegistrar::class, fn() => new RouteMiddlewareRegistrar($app), LifetimeEnum::Singleton);
        $container->bind(RoutePresetRegistrar::class, fn() => new RoutePresetRegistrar(
            $app->make(RouteMiddlewareRegistrar::class),
            $app->config(),
        ), LifetimeEnum::Singleton);
        $container->bind(RouteFileLoader::class, fn() => new RouteFileLoader(
            paths: $app->make(PathManager::class),
            config: $app->config(),
            router: $app->make(RouterManager::class),
            files: $this->routeFiles($app->config()->get('router.files', ['web.php', 'api.php', 'auth.php'])),
        ), LifetimeEnum::Singleton);

        $container->bind(RouterManager::class, fn() => new RouterManager(
            config: $app->config(),
            factory: $app->make(WebrickRouterFactory::class),
            presets: $app->make(RoutePresetRegistrar::class),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.router', fn() => $container->get(RouterManager::class), LifetimeEnum::Singleton);
    }

    /**
     * @return list<string>
     */
    private function routeFiles(mixed $value): array
    {
        if (!is_array($value)) {
            return ['web.php', 'api.php', 'auth.php'];
        }

        $files = [];

        foreach ($value as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }

            $files[] = $file;
        }

        return $files === [] ? ['web.php', 'api.php', 'auth.php'] : $files;
    }
}
