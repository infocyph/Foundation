<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Closure;
use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\Webrick\Router\Definition\Registrar;

final class Route extends Facade
{
    public static function apiAuth(
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        static::manager()->apiAuth($callback, $prefix, $domain, $namePrefix);
    }

    public static function authMfa(
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        static::manager()->authMfa($callback, $prefix, $domain, $namePrefix);
    }

    public static function authVerified(
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        static::manager()->authVerified($callback, $prefix, $domain, $namePrefix);
    }

    public static function authWeb(
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        static::manager()->authWeb($callback, $prefix, $domain, $namePrefix);
    }

    public static function get(string $path, array|string|callable $handler, string|array|null $nameOrOptions = null): mixed
    {
        return static::manager()->get($path, $handler, $nameOrOptions);
    }

    public static function group(
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        array|Closure $middleware = [],
        string|Closure|null $namePrefix = null,
        ?Closure $callback = null,
    ): void {
        static::manager()->router()->group($prefix, $domain, $middleware, $namePrefix, $callback);
    }

    public static function groupWithPreset(
        string $preset,
        Closure $callback,
        array|string|null $prefix = null,
        string|array|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        static::manager()->groupWithPreset($preset, $callback, $prefix, $domain, $namePrefix);
    }

    public static function manager(): RouterManager
    {
        return static::app()->router();
    }

    public static function post(string $path, array|string|callable $handler, string|array|null $nameOrOptions = null): mixed
    {
        return static::manager()->post($path, $handler, $nameOrOptions);
    }

    public static function put(string $path, array|string|callable $handler, string|array|null $nameOrOptions = null): mixed
    {
        return static::manager()->put($path, $handler, $nameOrOptions);
    }

    public static function registrar(): Registrar
    {
        return static::manager()->router();
    }
}
