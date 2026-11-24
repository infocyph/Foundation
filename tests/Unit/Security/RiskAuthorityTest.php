<?php

declare(strict_types=1);

namespace Infocyph;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\Foundation\Security\Risk\RiskAuthority;
use InvalidArgumentException;
use LogicException;

it('requires explicitly qualified shared fail-closed integrity-protected atomic authority', function (): void {
    $key = str_repeat('k', 32);
    $options = new CacheOptions(integrityKey: str_repeat('i', 32), failOpen: false);
    expect(fn() => new RiskAuthority(Cache::memory(options: $options), $key))->toThrow(LogicException::class)
        ->and(fn() => new RiskAuthority(Cache::nullStore($options), $key, shared: true))->toThrow(LogicException::class)
        ->and(fn() => new RiskAuthority(Cache::memory(options: new CacheOptions(failOpen: true)), $key, shared: true))->toThrow(LogicException::class)
        ->and(fn() => new RiskAuthority(Cache::memory(options: $options), 'short'))->toThrow(InvalidArgumentException::class);
});
