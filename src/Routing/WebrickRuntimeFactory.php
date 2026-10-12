<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;

/** Development-only router identities generated from explicit native dependencies. */
final class WebrickRuntimeFactory
{
    public static function registrar(WebrickRouterFactory $factory): Registrar
    {
        return $factory->router();
    }

    public static function routes(WebrickRouterFactory $factory): Collection
    {
        return $factory->routes();
    }
}
