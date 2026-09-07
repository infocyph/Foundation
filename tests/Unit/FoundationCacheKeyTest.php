<?php

declare(strict_types=1);

use Infocyph\Foundation\Cache\FoundationCacheKey;

it('derives deterministic domain-separated PSR-safe security keys with sha3-256', function (): void {
    $logical = str_repeat('attacker:{}()/\\@:segment:', 16);
    $first = FoundationCacheKey::security('at', 'foundation.auth.ttl.v1', $logical);
    $repeat = FoundationCacheKey::security('at', 'foundation.auth.ttl.v1', $logical);
    $otherDomain = FoundationCacheKey::security('at', 'foundation.webhook.replay.v1', $logical);
    $digest = hash('sha3-256', "foundation.auth.ttl.v1\0" . $logical, true);
    $expected = 'at.' . rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');

    expect($first)->toBe($expected)
        ->and($repeat)->toBe($first)
        ->and($otherDomain)->not->toBe($first)
        ->and(strlen($first))->toBeLessThanOrEqual(64)
        ->and(preg_match('/^[A-Za-z0-9_.-]+$/D', $first))->toBe(1)
        ->and($first)->not->toContain(':')
        ->and($first)->not->toContain($logical);
});

it('separates fast fingerprints from security-sensitive key derivation', function (): void {
    $logical = str_repeat('configuration/route:value:', 8);
    $fingerprint = FoundationCacheKey::fingerprint('cfg', 'foundation.config.cache.v1', $logical);
    $security = FoundationCacheKey::security('sec', 'foundation.config.cache.v1', $logical);
    $otherDomain = FoundationCacheKey::security('sec', 'foundation.auth.ttl.v1', $logical);
    $digest = hash('sha3-256', "foundation.config.cache.v1\0" . $logical, true);
    $expectedSecurity = 'sec.' . rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');

    expect($fingerprint)->toBe('cfg.' . hash('xxh128', "foundation.config.cache.v1\0" . $logical))
        ->and($security)->toBe($expectedSecurity)
        ->and($fingerprint)->not->toBe($security)
        ->and($security)->not->toBe($otherDomain)
        ->and(strlen($fingerprint))->toBeLessThanOrEqual(64)
        ->and(strlen($security))->toBeLessThanOrEqual(64)
        ->and(preg_match('/^[A-Za-z0-9_.-]+$/D', $fingerprint))->toBe(1)
        ->and(preg_match('/^[A-Za-z0-9_.-]+$/D', $security))->toBe(1);
});

it('rejects invalid physical key prefixes', function (): void {
    FoundationCacheKey::security('bad:prefix', 'foundation.auth.ttl.v1', 'logical');
})->throws(InvalidArgumentException::class);
