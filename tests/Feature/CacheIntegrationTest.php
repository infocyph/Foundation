<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\ChainCacheAdapter;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\Foundation\Foundation;

it('creates sqlite cache stores from database connections and applies strict serialization policy', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-cache-' . uniqid('', true);
    mkdir($basePath . '/storage/cache', 0775, true);
    mkdir($basePath . '/database', 0775, true);

    $app = Foundation::web([
        'app' => [
            'base_path' => $basePath,
        ],
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
                        'driver' => 'pdo',
                        'prefix' => 'cache:test:lock:',
                    ],
                ],
            ],
        ],
    ]);

    $cache = $app->cache()->store();

    try {
        expect($cache->set('name', 'Ada'))->toBeTrue()
            ->and($cache->get('name'))->toBe('Ada')
            ->and($cache->exportMetrics())->toHaveKey('pdo');

        expect($basePath . '/database/cache.sqlite')->toBeFile();

        $cache->set('user', (object) ['name' => 'Ada']);

        test()->fail('Expected object cache payloads to be blocked by strict serialization policy.');
    } catch (\InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('Object payload');
    } finally {
        $cache->configurePayloadCompression(null);
        $cache->configurePayloadSecurity(null, 8_388_608);
        $cache->configureSerializationSecurity(true, true);
    }
});

it('builds tiered cache stores from named store descriptors and applies file locking', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-cache-tiered-' . uniqid('', true);
    mkdir($basePath . '/storage/cache/tiered', 0775, true);
    mkdir($basePath . '/storage/cache/locks', 0775, true);

    $app = Foundation::web([
        'app' => [
            'base_path' => $basePath,
        ],
        'cache' => [
            'default' => 'tiered',
            'prefix' => 'suite:',
            'stores' => [
                'memory' => [
                    'driver' => 'memory',
                ],
                'file' => [
                    'driver' => 'file',
                    'path' => 'storage/cache/tiered/file',
                ],
                'tiered' => [
                    'driver' => 'tiered',
                    'tiers' => [
                        ['store' => 'memory'],
                        ['store' => 'file'],
                    ],
                    'lock' => [
                        'driver' => 'file',
                        'path' => 'storage/cache/locks',
                    ],
                ],
            ],
        ],
    ]);

    $cache = $app->cache()->store();

    expect($cache->set('framework', 'Infbyte'))->toBeTrue()
        ->and($cache->get('framework'))->toBe('Infbyte')
        ->and($cache->count())->toBe(1);

    $reflection = new \ReflectionClass($cache);
    $adapterProperty = $reflection->getProperty('adapter');
    $adapter = $adapterProperty->getValue($cache);

    $lockProperty = $reflection->getProperty('lockProvider');
    $lockProvider = $lockProperty->getValue($cache);

    expect($adapter)->toBeInstanceOf(ChainCacheAdapter::class)
        ->and($lockProvider)->toBeInstanceOf(FileLockProvider::class);

    $chainReflection = new \ReflectionClass($adapter);
    $poolsProperty = $chainReflection->getProperty('pools');
    $pools = $poolsProperty->getValue($adapter);

    expect($pools)->toHaveCount(2);
});
