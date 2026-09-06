<?php

declare(strict_types=1);

use Infocyph\Foundation\Cache\FoundationCacheKey;

it('derives deterministic domain-separated PSR-safe security keys with xxh128', function (): void {
    $logical = str_repeat('attacker:{}()/\\@:segment:', 16);
    $first = FoundationCacheKey::security('at', 'foundation.auth.ttl.v1', $logical);
    $repeat = FoundationCacheKey::security('at', 'foundation.auth.ttl.v1', $logical);
    $otherDomain = FoundationCacheKey::security('at', 'foundation.webhook.replay.v1', $logical);

    $expected = 'at.' . hash('xxh128', "foundation.auth.ttl.v1\0" . $logical);

    expect($first)->toBe($expected)
        ->and($repeat)->toBe($first)
        ->and($otherDomain)->not->toBe($first)
        ->and(strlen($first))->toBeLessThanOrEqual(64)
        ->and(preg_match('/^[A-Za-z0-9_.-]+$/D', $first))->toBe(1)
        ->and($first)->not->toContain(':')
        ->and($first)->not->toContain($logical);
});

it('keeps prefixes and domains separated while sharing the fast xxh128 mapping policy', function (): void {
    $logical = str_repeat('configuration/route:value:', 8);
    $fingerprint = FoundationCacheKey::fingerprint('cfg', 'foundation.config.cache.v1', $logical);
    $security = FoundationCacheKey::security('sec', 'foundation.config.cache.v1', $logical);
    $otherDomain = FoundationCacheKey::security('sec', 'foundation.auth.ttl.v1', $logical);

    expect($fingerprint)->toBe('cfg.' . hash('xxh128', "foundation.config.cache.v1\0" . $logical))
        ->and($security)->toBe('sec.' . hash('xxh128', "foundation.config.cache.v1\0" . $logical))
        ->and($fingerprint)->not->toBe($security)
        ->and($security)->not->toBe($otherDomain)
        ->and(strlen($fingerprint))->toBeLessThanOrEqual(64)
        ->and(preg_match('/^[A-Za-z0-9_.-]+$/D', $fingerprint))->toBe(1);
});

it('rejects invalid physical key prefixes', function (): void {
    FoundationCacheKey::security('bad:prefix', 'foundation.auth.ttl.v1', 'logical');
})->throws(InvalidArgumentException::class);
