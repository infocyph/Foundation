<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\Webrick\Router\Definition\Registrar;

/** @mixin RouterManager */
final class Route extends ManagerFacade
{
    public static function manager(): RouterManager
    {
        return self::app()->router();
    }

    public static function registrar(): Registrar
    {
        return self::manager()->router();
    }
}
