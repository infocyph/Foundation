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
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
