<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
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
        $router = is_array($context->config['router'] ?? null) ? $context->config['router'] : [];

        // Live Webrick router composition remains a development/test path. Production
        // releases use WebReleaseCompiler + WebReleaseRuntime and never resolve this factory.
        $builder->bindFactory(WebrickMiddlewareFactory::class, fn() => new WebrickMiddlewareFactory(
            app: $app,
            config: $app->config(),
            logger: $app->make(LoggerInterface::class),
        ));
        $builder->singleton(RouteMiddlewareRegistrar::class, FactoryDefinition::construct(
            RouteMiddlewareRegistrar::class,
            [new ServiceReference(WebrickMiddlewareFactory::class)],
        ));
        $builder->bindFactory(WebrickRouterFactory::class, fn() => new WebrickRouterFactory(
            $app->config(),
            $app->make(WebrickMiddlewareFactory::class),
            $app->make(RouteMiddlewareRegistrar::class),
            $container,
            $app->make(LoggerInterface::class),
        ));
        $builder->singleton(RoutePresetRegistrar::class, FactoryDefinition::construct(
            RoutePresetRegistrar::class,
            [
                new ServiceReference(RouteMiddlewareRegistrar::class),
                new ServiceReference(ConfigRepository::class),
            ],
        ));
        $builder->singleton(OAuthRouteRegistrar::class, FactoryDefinition::construct(
            OAuthRouteRegistrar::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(RoutePresetRegistrar::class),
            ],
        ));
        $builder->bindFactory(Registrar::class, fn() => $app->make(WebrickRouterFactory::class)->router());
        $builder->bindFactory(Collection::class, fn() => $app->make(WebrickRouterFactory::class)->routes());
        $builder->singleton(RouteFileLoader::class, FactoryDefinition::construct(
            RouteFileLoader::class,
            [
                new ServiceReference(PathManager::class),
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(RoutePresetRegistrar::class),
                new ServiceReference(OAuthRouteRegistrar::class),
                $this->routeFiles($router['files'] ?? ['web.php', 'api.php', 'auth.php']),
            ],
        ));

        $builder->alias('foundation.router', Registrar::class);
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
