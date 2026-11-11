<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Foundation;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;

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
    }
});

it('isolates native cache operations through borrowed Fiber contexts and restores after cancellation', function (): void {
    $app = Foundation::worker([
        'app' => ['capabilities' => ['cache', 'runwire']],
        'cache' => [
            'default' => 'memory',
            'prefix' => 'foundation-cache-fiber-context:',
            'stores' => ['memory' => ['driver' => 'memory']],
        ],
    ])->boot();
    $store = $app->make(CacheManager::class)->store();
    $runtime = RuntimeContext::standalone();
    $first = RequestContext::create($runtime);
    $second = RequestContext::create($runtime);

    $create = static fn(RequestContext $request, string $key): Fiber => new Fiber(
        static fn(): mixed => CacheRunwire::share(
            $request,
            null,
            static fn(): mixed => $app->execution()->run(
                static function () use ($app, $store, $request, $key): string {
                    expect(CacheRunwire::current()?->request)->toBe($request)
                        ->and($store->set($key, $key, 60))->toBeTrue();

                    $captured = $app->runtime()->captureScopeContext();
                    $child = new Fiber(static fn(): mixed => $app->execution()->withinBorrowedScope(
                        $captured,
                        static fn(): array => [
                            CacheRunwire::current()?->request,
                            $store->get($key),
                        ],
                    ));
                    $child->start();
                    expect($child->getReturn())->toBe([$request, $key]);

                    Fiber::suspend($key);
                    CacheRunwire::checkpoint();

                    return $store->get($key);
                },
                [RuntimeContext::class => $runtime, RequestContext::class => $request],
            ),
            $runtime,
        ),
    );

    try {
        $left = $create($first, 'first');
        $right = $create($second, 'second');
        expect($left->start())->toBe('first')
            ->and($right->start())->toBe('second')
            ->and(CacheRunwire::current())->toBeNull()
            ->and($store->get('first'))->toBe('first')
            ->and($store->get('second'))->toBe('second');

        $first->cancel(CancellationReason::HOST_CANCELLED);
        expect(fn(): mixed => $left->resume())->toThrow(CancelledException::class)
            ->and($first->cancelled())->toBeTrue()
            ->and($second->cancelled())->toBeFalse();
        $right->resume();

        expect($right->getReturn())->toBe('second')
            ->and(CacheRunwire::current())->toBeNull()
            ->and(CacheRunwire::runtime())->toBeNull()
            ->and($store->get('second'))->toBe('second');
    } finally {
        $first->complete();
        $second->complete();
    }
});
