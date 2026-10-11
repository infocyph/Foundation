<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Foundation;

it('resolves the configured default and explicit store to the same instance in either order', function (): void {
    foreach ([false, true] as $namedFirst) {
        $application = Foundation::cli([
            'app' => ['capabilities' => ['cache']],
            'cache' => [
                'default' => 'memory',
                'prefix' => 'foundation-4-cache-identity:',
                'stores' => ['memory' => ['driver' => 'memory']],
            ],
        ]);

        $manager = $application->make(CacheManager::class);
        $first = $manager->store($namedFirst ? 'memory' : null);
        $second = $manager->store($namedFirst ? null : 'memory');

        expect($first)->toBe($second);

        $application->container()->unset();
    }
});

it('replaces both names of the configured default without divergent instances', function (): void {
    $application = Foundation::cli([
        'app' => ['capabilities' => ['cache']],
        'cache' => [
            'default' => 'memory',
            'prefix' => 'foundation-4-cache-replacement:',
            'stores' => ['memory' => ['driver' => 'memory']],
        ],
    ]);

    $manager = $application->make(CacheManager::class);
    $replacement = Cache::memory('foundation-4-cache-replacement-custom');

    $manager->useStore($replacement);

    expect($manager->store())->toBe($replacement)
        ->and($manager->store('memory'))->toBe($replacement);

    $application->container()->unset();
});

it('follows configuration default changes without using a reserved cache alias', function (): void {
    $application = Foundation::cli([
        'app' => ['capabilities' => ['cache']],
        'cache' => [
            'default' => 'memory',
            'prefix' => 'foundation-4-cache-switch:',
            'stores' => [
                'memory' => ['driver' => 'memory'],
                'secondary' => ['driver' => 'memory'],
            ],
        ],
    ]);
    $manager = $application->make(CacheManager::class);
    $original = $manager->store();
    $original->set('switch-marker', 'original', 60);

    $application->config()->set('cache.default', 'secondary');

    expect($manager->store())->toBe($manager->store('secondary'))
        ->and($manager->store())->not->toBe($original)
        ->and($manager->store('memory')->get('switch-marker'))->toBe('original')
        ->and($manager->store()->get('switch-marker'))->toBeNull();

    $application->container()->unset();
});

it('shares native lock providers by named resource but not acquired handles', function (): void {
    $application = Foundation::cli([
        'app' => ['capabilities' => ['cache']],
        'cache' => [
            'default' => 'memory',
            'prefix' => 'foundation-4-cache-lock:',
            'stores' => ['memory' => ['driver' => 'memory']],
        ],
    ]);
    $manager = $application->make(CacheManager::class);
    $provider = $manager->lock();
    $explicit = $manager->lock('memory');

    expect($provider)->toBe($explicit);

    $first = $provider->acquire('resource-one', 0.0, 5.0);
    $second = $explicit->acquire('resource-two', 0.0, 5.0);
    try {
        expect($first)->not->toBeNull()
            ->and($second)->not->toBeNull()
            ->and($first)->not->toBe($second);

        $manager->useStore(Cache::memory('foundation-4-replacement-lock'), 'memory');

        expect($manager->lock('memory'))->not->toBe($provider)
            ->and($manager->lock())->toBe($manager->lock('memory'));
    } finally {
        $provider->release($first);
        $explicit->release($second);
        $application->container()->unset();
    }
});
