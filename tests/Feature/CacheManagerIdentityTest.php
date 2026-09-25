<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Foundation;

it('canonicalizes default and explicit cache store identity in both access orders', function (): void {
    $first = Foundation::cli([
        'app' => ['capabilities' => ['cache']],
        'cache' => [
            'default' => 'memory',
            'prefix' => 'foundation-cache-identity:',
            'stores' => [
                'memory' => ['driver' => 'memory'],
                'secondary' => ['driver' => 'memory'],
            ],
        ],
    ]);
    $firstManager = $first->make(CacheManager::class);

    $default = $firstManager->store();
    $explicit = $firstManager->store('memory');

    expect($default)->toBe($explicit);
    $default->set('alias-check', 'default-first', 60);
    expect($explicit->get('alias-check'))->toBe('default-first');

    $second = Foundation::cli([
        'app' => ['capabilities' => ['cache']],
        'cache' => [
            'default' => 'memory',
            'prefix' => 'foundation-cache-identity-reverse:',
            'stores' => [
                'memory' => ['driver' => 'memory'],
                'secondary' => ['driver' => 'memory'],
            ],
        ],
    ]);
    $secondManager = $second->make(CacheManager::class);

    $explicitFirst = $secondManager->store('memory');
    $defaultSecond = $secondManager->store();

    expect($explicitFirst)->toBe($defaultSecond);
    $explicitFirst->set('alias-check', 'explicit-first', 60);
    expect($defaultSecond->get('alias-check'))->toBe('explicit-first');

    $first->container()->unset();
    $second->container()->unset();
});

it('follows an explicit default-store change without a reserved alias key', function (): void {
    $application = Foundation::cli([
        'app' => ['capabilities' => ['cache']],
        'cache' => [
            'default' => 'memory',
            'prefix' => 'foundation-cache-default-change:',
            'stores' => [
                'memory' => ['driver' => 'memory'],
                'secondary' => ['driver' => 'memory'],
            ],
        ],
    ]);
    $manager = $application->make(CacheManager::class);
    $memory = $manager->store();
    $memory->set('identity', 'memory', 60);

    $application->config()->set('cache.default', 'secondary');
    $secondary = $manager->store();

    expect($secondary)->toBe($manager->store('secondary'))
        ->and($secondary)->not->toBe($memory)
        ->and($secondary->get('identity'))->toBeNull()
        ->and($manager->store('memory')->get('identity'))->toBe('memory');

    $application->container()->unset();
});

it('applies default identity consistently to replacement delete and clear operations', function (): void {
    $application = Foundation::cli([
        'app' => ['capabilities' => ['cache']],
        'cache' => [
            'default' => 'memory',
            'prefix' => 'foundation-cache-replacement:',
            'stores' => [
                'memory' => ['driver' => 'memory'],
            ],
        ],
    ]);
    $manager = $application->make(CacheManager::class);
    $replacement = Cache::memory('foundation-cache-replacement-custom');

    $manager->useStore($replacement);
    expect($manager->store())->toBe($replacement)
        ->and($manager->store('memory'))->toBe($replacement);

    $manager->store('memory')->set('replace-me', 'value', 60);
    expect($manager->store()->delete('replace-me'))->toBeTrue()
        ->and($manager->store('memory')->get('replace-me'))->toBeNull();

    $manager->store()->set('clear-me', 'value', 60);
    expect($manager->store('memory')->clear())->toBeTrue()
        ->and($manager->store()->get('clear-me'))->toBeNull();

    $application->container()->unset();
});

it('keeps distinct named stores and application cache registries isolated', function (): void {
    $config = [
        'app' => ['capabilities' => ['cache']],
        'cache' => [
            'default' => 'memory',
            'prefix' => 'foundation-cache-isolation:',
            'stores' => [
                'memory' => ['driver' => 'memory'],
                'secondary' => ['driver' => 'memory'],
            ],
        ],
    ];

    $first = Foundation::cli($config);
    $second = Foundation::cli($config);
    $firstManager = $first->make(CacheManager::class);
    $secondManager = $second->make(CacheManager::class);

    $firstManager->store()->set('isolated', 'default', 60);
    $firstManager->store('secondary')->set('isolated', 'secondary', 60);

    expect($firstManager->store())->not->toBe($firstManager->store('secondary'))
        ->and($firstManager->store()->get('isolated'))->toBe('default')
        ->and($firstManager->store('secondary')->get('isolated'))->toBe('secondary')
        ->and($secondManager->store()->get('isolated'))->toBeNull()
        ->and($secondManager->store('secondary')->get('isolated'))->toBeNull();

    $first->container()->unset();
    $second->container()->unset();
});

it('reuses generation-owned lock providers without sharing lock handles', function (): void {
    $application = Foundation::cli([
        'app' => ['capabilities' => ['cache']],
        'cache' => [
            'default' => 'memory',
            'prefix' => 'foundation-cache-lock-identity:',
            'stores' => [
                'memory' => ['driver' => 'memory'],
            ],
        ],
    ]);
    $manager = $application->make(CacheManager::class);

    $default = $manager->lock();
    $explicit = $manager->lock('memory');

    expect($default)->toBe($explicit);

    $first = $default->acquire('session-one', 0.0, 5.0);
    $second = $explicit->acquire('session-two', 0.0, 5.0);

    try {
        expect($first)->not->toBeNull()
            ->and($second)->not->toBeNull()
            ->and($first)->not->toBe($second);

        $replacement = Cache::memory('foundation-cache-lock-replacement');
        $manager->useStore($replacement, 'memory');
        $replacementLock = $manager->lock('memory');

        expect($replacementLock)->not->toBe($default)
            ->and($manager->lock())->toBe($replacementLock);
    } finally {
        $default->release($first);
        $explicit->release($second);
        $application->container()->unset();
    }
});
