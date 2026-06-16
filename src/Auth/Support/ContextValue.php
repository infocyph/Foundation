<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

final class ContextValue
{
    /**
     * @param array<string, mixed> $context
     */
    public static function int(array $context, string $key, int $default): int
    {
        $value = $context[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function stringOrNull(array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
