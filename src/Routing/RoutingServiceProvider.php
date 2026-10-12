<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class RoutingServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        new MiddlewareConfigValidator(new ConfigRepository($context->config, $context->compiledConfig))->validate();
        $router = is_array($context->config['router'] ?? null) ? $context->config['router'] : [];

        // Live Webrick router composition remains a development/test path. Production
        // releases use WebReleaseCompiler + WebReleaseRuntime and never resolve this factory.
        $builder->factory(WebrickMiddlewareFactory::class, FactoryDefinition::construct(
            WebrickMiddlewareFactory::class,
            [
                new ServiceReference(ContainerInterface::class),
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(LoggerInterface::class),
            ],
        ));
        $builder->factory(RouteMiddlewareRegistrar::class, FactoryDefinition::construct(
            RouteMiddlewareRegistrar::class,
            [new ServiceReference(WebrickMiddlewareFactory::class)],
        ));
        $builder->factory(WebrickRouterFactory::class, FactoryDefinition::construct(
            WebrickRouterFactory::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(WebrickMiddlewareFactory::class),
                new ServiceReference(RouteMiddlewareRegistrar::class),
                new ServiceReference(Container::class),
                new ServiceReference(LoggerInterface::class),
            ],
        ));
        $builder->factory(RoutePresetRegistrar::class, FactoryDefinition::construct(
            RoutePresetRegistrar::class,
            [
                new ServiceReference(RouteMiddlewareRegistrar::class),
                new ServiceReference(ConfigRepository::class),
            ],
        ));
        $builder->factory(OAuthRouteRegistrar::class, FactoryDefinition::construct(
            OAuthRouteRegistrar::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(RoutePresetRegistrar::class),
            ],
        ));
        $builder->factory(Registrar::class, FactoryDefinition::staticFactory(
            WebrickRuntimeFactory::class,
            'registrar',
            [new ServiceReference(WebrickRouterFactory::class)],
        ));
        $builder->factory(Collection::class, FactoryDefinition::staticFactory(
            WebrickRuntimeFactory::class,
            'routes',
            [new ServiceReference(WebrickRouterFactory::class)],
        ));
        $builder->factory(RouteFileLoader::class, FactoryDefinition::construct(
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
