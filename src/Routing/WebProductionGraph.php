<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Container\ContainerCacheManager;
use Infocyph\Foundation\Http\HttpKernel;
use Infocyph\Foundation\Operations\MaintenanceManager;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Router\Build\CompiledRouterArtifact;
use Infocyph\Webrick\Router\Build\RouterBuildResult;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Route\Collection;

/** Finalizes the web graph after route discovery and before production compilation/load. */
final class WebProductionGraph
{
    /** @var list<string> */
    private const array DEVELOPMENT_ONLY = [
        Application::class,
        Container::class,
        ContainerCacheManager::class,
        WebrickMiddlewareFactory::class,
        RouteMiddlewareRegistrar::class,
        WebrickRouterFactory::class,
        Registrar::class,
        Collection::class,
        RouteFileLoader::class,
        RoutePresetRegistrar::class,
        OAuthRouteRegistrar::class,
        ErrorHandler::class,
        RouterKernel::class,
        HttpKernel::class,
        MaintenanceManager::class,
        'foundation.router',
        'foundation.http',
    ];

    public function prepareBuild(ContainerBuilder $builder, RouterBuildResult $routes): void
    {
        new WebRouteGraphEnricher()->enrich($builder, $routes);
        $this->removeDevelopmentDefinitions($builder);
    }

    public function prepareRuntime(ContainerBuilder $builder, CompiledRouterArtifact $artifact): void
    {
        new WebRouteGraphEnricher()->enrichArtifact($builder, $artifact);
        $this->removeDevelopmentDefinitions($builder);
    }

    private function removeDevelopmentDefinitions(ContainerBuilder $builder): void
    {
        foreach (self::DEVELOPMENT_ONLY as $id) {
            if ($builder->definitions()->has($id)) {
                $builder->unbind($id);
            }
        }
    }
}
