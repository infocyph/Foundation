<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRuntime;

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        return ConfigRuntime::appPath($path);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return ConfigRuntime::basePath($path);
    }
}

if (!function_exists('bootstrap_path')) {
    function bootstrap_path(string $path = ''): string
    {
        return ConfigRuntime::bootstrapPath($path);
    }
}

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return ConfigRuntime::configPath($path);
    }
}

if (!function_exists('database_path')) {
    function database_path(string $path = ''): string
    {
        return ConfigRuntime::databasePath($path);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $hasDefault = func_num_args() >= 2;
        $value = env_lookup($key);

        return env_resolve($value, $default, $hasDefault);
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return ConfigRuntime::publicPath($path);
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env($key, $default);

        return is_bool($value) ? $value : $default;
    }
}

if (!function_exists('env_int')) {
    function env_int(string $key, int $default = 0): int
    {
        $value = env($key, $default);

        return is_int($value) ? $value : $default;
    }
}

if (!function_exists('env_string')) {
    function env_string(string $key, string $default = ''): string
    {
        $value = env($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}

if (!function_exists('resource_path')) {
    function resource_path(string $path = ''): string
    {
        return ConfigRuntime::resourcePath($path);
    }
}

if (!function_exists('routes_path')) {
    function routes_path(string $path = ''): string
    {
        return ConfigRuntime::routesPath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return ConfigRuntime::storagePath($path);
    }
}

if (!function_exists('env_lookup')) {
    function env_lookup(string $key): string|false
    {
        if (array_key_exists($key, $_ENV)) {
            return is_string($_ENV[$key]) ? $_ENV[$key] : false;
        }

        if (array_key_exists($key, $_SERVER)) {
            return is_string($_SERVER[$key]) ? $_SERVER[$key] : false;
        }

        return getenv($key);
    }
}

if (!function_exists('env_resolve')) {
    function env_resolve(string|false $value, mixed $default, bool $hasDefault): mixed
    {
        if ($value === false) {
            return $hasDefault ? $default : null;
        }

        if ($value === '') {
            return $hasDefault ? $default : null;
        }

        return ConfigRuntime::normalizeEnvValue($value, $default);
    }
}
