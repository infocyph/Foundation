<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\TieredCacheAdapter;
use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;
use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Foundation;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

it('exposes one native CacheLayer store through Foundation PSR and DBLayer bindings', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-cache-' . uniqid('', true);
    mkdir($basePath . '/storage/cache/locks', 0775, true);
    mkdir($basePath . '/database', 0775, true);

    $app = Foundation::web([
        'app' => ['base_path' => $basePath],
        'database' => [
            'default' => 'cache',
            'connections' => [
                'cache' => [
                    'driver' => 'sqlite',
                    'database' => 'database/cache.sqlite',
                ],
            ],
        ],
        'cache' => [
            'default' => 'database',
            'compression' => [
                'threshold_bytes' => 1,
                'level' => 5,
            ],
            'security' => [
                'integrity_key' => 'cache-secret',
                'max_payload_bytes' => 4096,
            ],
            'serialization' => [
                'allow_closure_payloads' => false,
                'allow_object_payloads' => false,
            ],
            'stores' => [
                'database' => [
                    'driver' => 'pdo',
                    'connection' => 'cache',
                    'table' => 'cache_entries',
                    'lock' => [
                        'driver' => 'file',
                        'path' => 'storage/cache/locks',
                        'prefix' => 'cache:test:lock:',
                    ],
                ],
            ],
        ],
    ]);

    try {
        $cache = $app->make(CacheInterface::class);

        expect($cache)->toBeInstanceOf(Cache::class)
            ->and($app->make(Cache::class))->toBe($cache)
            ->and($app->make(AuthenticationStateCacheInterface::class))->toBe($cache)
            ->and($app->make(SimpleCacheInterface::class))->toBe($cache)
            ->and($app->make(CacheItemPoolInterface::class))->toBe($cache)
            ->and($app->make('foundation.cache'))->toBe($cache)
            ->and(DB::cache())->toBe($cache)
            ->and($cache->set('name', 'Ada'))->toBeTrue()
            ->and($cache->get('name'))->toBe('Ada')
            ->and($cache->exportMetrics())->toHaveKey('pdo')
            ->and($basePath . '/database/cache.sqlite')->toBeFile()
            ->and($cache->set('user', (object) ['name' => 'Ada']))->toBeFalse()
            ->and($cache->get('user'))->toBeNull();
    } finally {
        DB::purge();
    }
});

it('passes CacheLayer-native tier descriptors and applies Foundation lock policy', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-cache-tiered-' . uniqid('', true);
    mkdir($basePath . '/storage/cache/tiered', 0775, true);
    mkdir($basePath . '/storage/cache/locks', 0775, true);

    $app = Foundation::web([
        'app' => ['base_path' => $basePath],
        'cache' => [
            'default' => 'tiered',
            'prefix' => 'suite:',
            'stores' => [
                'tiered' => [
                    'driver' => 'tiered',
                    'tiers' => [
                        ['driver' => 'memory'],
                        [
                            'driver' => 'file',
                            'dir' => 'storage/cache/tiered/file',
                        ],
                    ],
                    'lock' => [
                        'driver' => 'file',
                        'path' => 'storage/cache/locks',
                    ],
                ],
            ],
        ],
    ]);

    $cache = $app->make(CacheInterface::class);

    expect($cache->set('framework', 'Infbyte'))->toBeTrue()
        ->and($cache->get('framework'))->toBe('Infbyte');

    $reflection = new ReflectionClass($cache);
    $adapter = $reflection->getProperty('adapter')->getValue($cache);
    $lockProvider = $reflection->getProperty('lockProvider')->getValue($cache);

    expect($adapter)->toBeInstanceOf(TieredCacheAdapter::class)
        ->and($lockProvider)->toBeInstanceOf(FileLockProvider::class);

    $chainReflection = new ReflectionClass($adapter);
    $pools = $chainReflection->getProperty('pools')->getValue($adapter);

    expect($pools)->toHaveCount(2);
});

it('exposes the configured CacheLayer lock provider directly', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-cache-lock-' . uniqid('', true);
    mkdir($basePath . '/storage/cache/locks', 0775, true);

    $app = Foundation::web([
        'app' => ['base_path' => $basePath],
        'cache' => [
            'default' => 'memory',
            'lock' => [
                'driver' => 'file',
                'path' => 'storage/cache/locks',
            ],
            'stores' => [
                'memory' => ['driver' => 'memory'],
            ],
        ],
    ]);

    $cache = $app->make(CacheInterface::class);
    $lockProperty = new ReflectionProperty($cache, 'lockProvider');
    $cacheLock = $lockProperty->getValue($cache);
    $sharedLock = $app->make(LockProviderInterface::class);

    expect($cacheLock)->toBeInstanceOf(FileLockProvider::class)
        ->and($sharedLock)->toBeInstanceOf(FileLockProvider::class);

    $handle = $sharedLock->acquire('shared-lock', 0.0, 10.0);
    expect($handle)->not->toBeNull()
        ->and($sharedLock->refresh($handle, 10.0))->toBeTrue();
    $sharedLock->release($handle);
});

it('keeps bounded process-local memoizers warm without clearing the shared cache', function (): void {
    $app = Foundation::web([
        'cache' => [
            'default' => 'memory',
            'stores' => [
                'memory' => ['driver' => 'memory'],
            ],
        ],
    ]);

    $cache = $app->make(CacheInterface::class);
    $memoizer = $app->make(Memoizer::class);
    $once = $app->make(OnceMemoizer::class);
    $memoizer->flush();
    $once->flush();

    $memoCalls = 0;
    $onceCalls = 0;
    $resolver = static function () use (&$memoCalls): int {
        return ++$memoCalls;
    };

    $cache->set('persistent', 'shared');

    $first = [
        $memoizer->get($resolver),
        $memoizer->get($resolver),
        foundationCacheOnceValue($once, $onceCalls),
        foundationCacheOnceValue($once, $onceCalls),
    ];
    $second = [
        $memoizer->get($resolver),
        $memoizer->get($resolver),
        foundationCacheOnceValue($once, $onceCalls),
        foundationCacheOnceValue($once, $onceCalls),
    ];

    expect($first)->toBe([1, 1, 1, 1])
        ->and($second)->toBe([1, 1, 1, 1])
        ->and($memoCalls)->toBe(1)
        ->and($onceCalls)->toBe(1)
        ->and($cache->get('persistent'))->toBe('shared');
});

function foundationCacheOnceValue(OnceMemoizer $memoizer, int &$calls): int
{
    return $memoizer->once(static function () use (&$calls): int {
        return ++$calls;
    });
}