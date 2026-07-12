<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final class ConfigRuntime
{
    private static string $basePath = '.';

    public static function activate(string $basePath): void
    {
        self::$basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
    }

    public static function appPath(string $path = ''): string
    {
        return self::namedPath('app', $path);
    }

    public static function basePath(string $path = ''): string
    {
        return self::join(self::$basePath, $path);
    }

    public static function bootstrapPath(string $path = ''): string
    {
        return self::namedPath('bootstrap', $path);
    }

    public static function configPath(string $path = ''): string
    {
        return self::namedPath('config', $path);
    }

    public static function databasePath(string $path = ''): string
    {
        return self::namedPath('database', $path);
    }

    public static function normalizeEnvValue(mixed $value, mixed $default = null): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => self::castScalarString($value, $default),
        };
    }

    public static function publicPath(string $path = ''): string
    {
        return self::namedPath('public', $path);
    }

    public static function resourcePath(string $path = ''): string
    {
        return self::namedPath('resources', $path);
    }

    public static function routesPath(string $path = ''): string
    {
        return self::namedPath('routes', $path);
    }

    public static function storagePath(string $path = ''): string
    {
        return self::namedPath('storage', $path);
    }

    private static function castScalarString(string $value, mixed $default): mixed
    {
        return match (true) {
            is_int($default) && is_numeric($value) => (int) $value,
            is_float($default) && is_numeric($value) => (float) $value,
            default => $value,
        };
    }

    private static function join(string $base, string $path = ''): string
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR);

        if ($path === '') {
            return $base;
        }

        return $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private static function namedPath(string $root, string $path = ''): string
    {
        return self::join(self::basePath($root), $path);
    }
}
