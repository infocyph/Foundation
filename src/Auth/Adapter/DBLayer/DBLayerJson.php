<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Support\ValueNormalizer;

final class DBLayerJson
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return ValueNormalizer::associativeArray($decoded);
    }

    /**
     * @return list<string>
     */
    public static function decodeStringList(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return ValueNormalizer::stringList($decoded);
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function encode(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param list<mixed> $value
     */
    public static function encodeList(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
