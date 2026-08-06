<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Psr\Log\LoggerInterface;

final class RoutingServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $this->bindFactory($container, WebrickMiddlewareFactory::class, fn() => new WebrickMiddlewareFactory(
            app: $app,
            config: $app->config(),
            logger: $app->make(LoggerInterface::class),
        ), LifetimeEnum::Singleton);
        $this->bindRecipe($container, RouteMiddlewareRegistrar::class, RouteMiddlewareRegistrar::class, [
            new ServiceReference(Application::class),
        ]);
        $this->bindFactory($container, WebrickRouterFactory::class, fn() => new WebrickRouterFactory(
            $app->config(),
            $app->make(WebrickMiddlewareFactory::class),
            $app->make(RouteMiddlewareRegistrar::class),
            $container,
            $app->make(LoggerInterface::class),
        ), LifetimeEnum::Singleton);
        $this->bindRecipe($container, RoutePresetRegistrar::class, RoutePresetRegistrar::class, [
            new ServiceReference(RouteMiddlewareRegistrar::class),
            new ServiceReference(\Infocyph\Foundation\Config\ConfigRepository::class),
        ]);
        $this->bindFactory($container, RouteFileLoader::class, fn() => new RouteFileLoader(
            paths: $app->make(PathManager::class),
            config: $app->config(),
            router: $app->make(RouterManager::class),
            files: $this->routeFiles($app->config()->get('router.files', ['web.php', 'api.php', 'auth.php'])),
        ), LifetimeEnum::Singleton);

        $this->bindRecipe($container, RouterManager::class, RouterManager::class, [
            new ServiceReference(\Infocyph\Foundation\Config\ConfigRepository::class),
            new ServiceReference(WebrickRouterFactory::class),
            new ServiceReference(RoutePresetRegistrar::class),
        ]);

        $this->bindFactory($container, 'foundation.router', fn() => $container->get(RouterManager::class), LifetimeEnum::Singleton);
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
