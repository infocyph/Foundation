<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Http\HttpKernel;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
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
        'foundation.router',
        'foundation.http',
    ];

    public function prepare(ContainerBuilder $builder): void
    {
        $this->removeDevelopmentDefinitions($builder);
    }

    private function removeDevelopmentDefinitions(ContainerBuilder $builder): void
    {
        foreach (self::DEVELOPMENT_ONLY as $id) {
            $builder->unbind($id);
        }
    }
}
