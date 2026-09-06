<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

/**
 * Canonical logical-to-physical key encoding for Foundation-owned cache state.
 *
 * CacheLayer owns physical key grammar. Foundation owns the semantic domains
 * of its logical state and therefore derives compact, non-revealing keys at
 * the integration boundary instead of weakening CacheLayer's PSR key rules.
 */
final class FoundationCacheKey
{
    private const int MAX_PHYSICAL_LENGTH = 64;

    private const int MAX_PREFIX_LENGTH = 20;

    public static function fingerprint(string $prefix, string $domain, string $logicalKey): string
    {
        return self::physical(
            $prefix,
            hash('xxh128', self::material($domain, $logicalKey)),
        );
    }

    public static function security(string $prefix, string $domain, string $logicalKey): string
    {
        $digest = hash('sha3-256', self::material($domain, $logicalKey), true);
        $encoded = rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');

        return self::physical($prefix, $encoded);
    }

    private static function material(string $domain, string $logicalKey): string
    {
        if ($domain === '') {
            throw new \InvalidArgumentException('Foundation cache key domain must not be empty.');
        }

        return $domain . "\0" . $logicalKey;
    }

    private static function physical(string $prefix, string $encoded): string
    {
        if (
            $prefix === ''
            || strlen($prefix) > self::MAX_PREFIX_LENGTH
            || preg_match('/^[A-Za-z0-9_.-]+$/D', $prefix) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Foundation cache key prefix must be 1-20 PSR-safe characters.',
            );
        }

        $key = $prefix . '.' . $encoded;
        if (strlen($key) > self::MAX_PHYSICAL_LENGTH) {
            throw new \LogicException('Foundation physical cache key exceeds CacheLayer limits.');
        }

        return $key;
    }
}
