<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\ArrayKit\Config\Support\EnvReference;

final class ConfigExportValidator
{
    private const int MAX_DEPTH = 64;

    /**
     * @param array<string, mixed> $config
     */
    public static function assertCacheable(array $config): void
    {
        self::walk($config, '', 0, allowDelayed: true);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function assertExportable(array $config): void
    {
        self::walk($config, '', 0, allowDelayed: false);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function isExportable(array $config): bool
    {
        try {
            self::assertExportable($config);
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    private static function walk(
        mixed $value,
        string $path,
        int $depth,
        bool $allowDelayed,
    ): void {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException(sprintf(
                'Configuration value "%s" exceeds the maximum cacheable nesting depth of %d.',
                $path !== '' ? $path : '<root>',
                self::MAX_DEPTH,
            ));
        }

        if ($value === null || is_scalar($value)) {
            return;
        }

        if ($allowDelayed && ($value instanceof EnvReference || $value instanceof \Closure)) {
            return;
        }

        if (!is_array($value)) {
            throw new \RuntimeException(sprintf(
                'Configuration value "%s" cannot be cached because %s values are not exportable. Use scalar data, arrays, or class-string descriptors instead.',
                $path !== '' ? $path : '<root>',
                get_debug_type($value),
            ));
        }

        foreach ($value as $key => $entry) {
            $segment = is_int($key) ? (string) $key : $key;
            self::walk(
                $entry,
                $path === '' ? $segment : $path . '.' . $segment,
                $depth + 1,
                $allowDelayed,
            );
        }
    }
}
