<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Closure;
use DateTimeInterface;
use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Definition\Registrar;

final class Route extends Facade
{
    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public static function apiAuth(
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        self::manager()->apiAuth($callback, $prefix, $domain, $namePrefix);
    }

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public static function authMfa(
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        self::manager()->authMfa($callback, $prefix, $domain, $namePrefix);
    }

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public static function authVerified(
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        self::manager()->authVerified($callback, $prefix, $domain, $namePrefix);
    }

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public static function authWeb(
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        self::manager()->authWeb($callback, $prefix, $domain, $namePrefix);
    }

    /**
     * @param array{0:string|object,1:string}|callable|string $handler
     * @param array<string, mixed>|string|null $nameOrOptions
     */
    public static function get(string $path, array|string|callable $handler, string|array|null $nameOrOptions = null): RouteInterface
    {
        return self::registrar()->get($path, $handler, $nameOrOptions);
    }

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     * @param list<mixed>|Closure $middleware
     */
    public static function group(
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        array|Closure $middleware = [],
        string|Closure|null $namePrefix = null,
        ?Closure $callback = null,
    ): void {
        self::manager()->router()->group($prefix, $domain, $middleware, $namePrefix, $callback);
    }

    /**
     * @param list<string>|string|null $prefix
     * @param list<string>|string|Closure|null $domain
     */
    public static function groupWithPreset(
        string $preset,
        Closure $callback,
        array|string|null $prefix = null,
        array|string|Closure|null $domain = null,
        ?string $namePrefix = null,
    ): void {
        self::manager()->groupWithPreset($preset, $callback, $prefix, $domain, $namePrefix);
    }

    public static function manager(): RouterManager
    {
        return self::app()->router();
    }

    /**
     * @param array{0:string|object,1:string}|callable|string $handler
     * @param array<string, mixed>|string|null $nameOrOptions
     */
    public static function post(string $path, array|string|callable $handler, string|array|null $nameOrOptions = null): RouteInterface
    {
        return self::registrar()->post($path, $handler, $nameOrOptions);
    }

    /**
     * @param array{0:string|object,1:string}|callable|string $handler
     * @param array<string, mixed>|string|null $nameOrOptions
     */
    public static function put(string $path, array|string|callable $handler, string|array|null $nameOrOptions = null): RouteInterface
    {
        return self::registrar()->put($path, $handler, $nameOrOptions);
    }

    public static function registrar(): Registrar
    {
        return self::manager()->router();
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public static function signedUrlFor(
        string $name,
        array $params = [],
        array $query = [],
        ?int $ttl = null,
        bool $absolute = false,
        ?string $payloadMode = null,
    ): string {
        return self::manager()->signedUrlFor($name, $params, $query, $ttl, $absolute, $payloadMode);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public static function temporaryUrlFor(
        string $name,
        array $params = [],
        array $query = [],
        int $ttl = 900,
        bool $absolute = false,
        ?string $payloadMode = null,
    ): string {
        return self::manager()->temporaryUrlFor($name, $params, $query, $ttl, $absolute, $payloadMode);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public static function temporaryUrlUntil(
        string $name,
        DateTimeInterface $expiresAt,
        array $params = [],
        array $query = [],
        bool $absolute = false,
        ?string $payloadMode = null,
    ): string {
        return self::manager()->temporaryUrlUntil($name, $expiresAt, $params, $query, $absolute, $payloadMode);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public static function urlFor(string $name, array $params = [], array $query = [], bool $absolute = false): string
    {
        return self::manager()->urlFor($name, $params, $query, $absolute);
    }
}
