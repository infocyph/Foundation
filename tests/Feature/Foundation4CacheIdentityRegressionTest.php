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
