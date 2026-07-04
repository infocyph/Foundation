<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Application\Application;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class App extends Facade
{
    public static function boot(): Application
    {
        return self::app()->boot();
    }

    public static function config(?string $key = null, mixed $default = null): mixed
    {
        return $key === null
            ? self::app()->config()
            : self::app()->config()->get($key, $default);
    }

    public static function handle(Request $request): Response
    {
        return self::app()->handle($request);
    }

    public static function instance(): Application
    {
        return self::app();
    }
}
