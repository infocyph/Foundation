<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Support;

final class ValueNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public static function associativeArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }

    public static function bool(mixed $value, bool $default): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_string($value) => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            is_int($value) => $value !== 0,
            default => $default,
        };
    }

    public static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) && $value !== ''
            ? $value
            : $default;
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }
}
