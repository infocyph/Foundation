<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final class ConfigMerger
{
    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                isset($base[$key], $value)
                && is_array($base[$key])
                && is_array($value)
            ) {
                if (array_is_list($base[$key]) || array_is_list($value)) {
                    $base[$key] = $value;

                    continue;
                }

                $base[$key] = self::merge(self::map($base[$key]), self::map($value));

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function mergeMany(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $merged = self::merge($merged, $item);
        }

        return $merged;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
