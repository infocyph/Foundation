<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Log\LoggerInterface;

final class RoutingServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $app = $this->application($builder, $context);
        new MiddlewareConfigValidator($app->config())->validate();
        $container = $builder->development();

        // Webrick live router objects remain an explicit Phase-5 dynamic island.
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
        $this->bindRecipe($container, OAuthRouteRegistrar::class, OAuthRouteRegistrar::class, [
            new ServiceReference(\Infocyph\Foundation\Config\ConfigRepository::class),
            new ServiceReference(RoutePresetRegistrar::class),
        ]);
        $this->bindFactory($container, Registrar::class, fn() => $app->make(WebrickRouterFactory::class)->router(), LifetimeEnum::Singleton);
        $this->bindFactory($container, Collection::class, fn() => $app->make(WebrickRouterFactory::class)->routes(), LifetimeEnum::Singleton);
        $this->bindFactory($container, RouteFileLoader::class, fn() => new RouteFileLoader(
            paths: $app->make(PathManager::class),
            config: $app->config(),
            router: $app->make(Registrar::class),
            presets: $app->make(RoutePresetRegistrar::class),
            oauth: $app->make(OAuthRouteRegistrar::class),
            files: $this->routeFiles($context->config['router']['files'] ?? ['web.php', 'api.php', 'auth.php']),
        ), LifetimeEnum::Singleton);

        $container->alias('foundation.router', Registrar::class, LifetimeEnum::Singleton);
    }

    /** @return list<string> */
    private function routeFiles(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ConfigurationException('router.files must be a list of route filenames.');
        }

        $files = [];
        foreach ($value as $index => $file) {
            if (!is_string($file) || trim($file) === '') {
                throw new ConfigurationException(sprintf(
                    'router.files.%s must be a non-empty route filename.',
                    (string) $index,
                ));
            }
            $files[] = $file;
        }

        return array_values(array_unique($files));
    }
}
