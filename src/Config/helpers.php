<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Config\Support\Environment;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        if (!Environment::has($key)) {
            return $default;
        }

        $value = Environment::get($key);
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return $default;
        }

        $normalized = strtolower($trimmed);
        if (in_array($normalized, ['true', '(true)'], true)) {
            return true;
        }
        if (in_array($normalized, ['false', '(false)'], true)) {
            return false;
        }
        if (in_array($normalized, ['null', '(null)'], true)) {
            return null;
        }
        if (in_array($normalized, ['empty', '(empty)'], true)) {
            return '';
        }

        if (is_int($default) && filter_var($trimmed, FILTER_VALIDATE_INT) !== false) {
            return (int) $trimmed;
        }
        if (is_float($default) && is_numeric($trimmed)) {
            return (float) $trimmed;
        }

        return $trimmed;
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
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
