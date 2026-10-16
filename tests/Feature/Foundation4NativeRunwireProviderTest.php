<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;

it('forwards published native providers through compiled and borrowed execution scopes and recycled leases', function (RuntimeMode $mode, bool $pooled, bool $throughLibrary, bool $compiled): void {
    $project = sys_get_temp_dir() . '/foundation-native-provider-' . bin2hex(random_bytes(6));
    mkdir($project . '/bootstrap/cache', 0700, true);
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'database' => [
            'default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']],
            'pool' => ['enabled' => $pooled, 'min_connections' => 0, 'max_connections' => 1],
        ],
        'messaging' => ['default_route' => ['transport' => 'memory']],
    ];
    $requests = [];
    $runtime = RuntimeContext::standalone();

    try {
        $artifact = $project . '/bootstrap/cache/runtime.php';
        $capabilities = ['database', 'messaging'];
        if ($compiled) {
            $report = new GeneratedRuntimeCompiler()->compile($config, $mode, $artifact, $capabilities);
            $generated = GeneratedRuntime::loadPrevalidated(
                $config, $mode, $artifact, $report['metadata_sha256'], $report['digest'], $capabilities,
            );
            $app = $generated->application;
        } else {
            $config['app']['capabilities'] = $capabilities;
            $app = match ($mode) {
                RuntimeMode::Cli => Foundation::cli($config)->boot(),
                RuntimeMode::Worker => Foundation::worker($config)->boot(),
                default => Foundation::scheduler($config)->boot(),
            };
        }
        $bus = $app->make(MessageBus::class);
        $factory = $app->make(DBLayerFactory::class);
        $execution = $app->execution();
        $previousConnection = null;

        for ($index = 0; $index < 2; $index++) {
            $request = RequestContext::create($runtime);
            $requests[] = $request;
            $callback = static function () use ($app, $execution, $factory, $bus, $runtime, $request): mixed {
                $connection = $factory->connection();
                expect($app->make(RuntimeContext::class))->toBe($runtime)
                    ->and($app->make(RequestContext::class))->toBe($request)
                    ->and($connection->scalar('SELECT 1'))->toBe(1)
                    ->and($connection->runwireBinding()['request'])->toBe($request)
                    ->and($bus->runwireBinding()->request())->toBe($request);
                $captured = $app->runtime()->captureScopeContext();
                $child = new Fiber(static fn(): mixed => $execution->withinBorrowedScope(
                    $captured,
                    static function () use ($app, $factory, $bus, $runtime, $request): mixed {
                        expect($app->make(RuntimeContext::class))->toBe($runtime)
                            ->and($app->make(RequestContext::class))->toBe($request)
                            ->and(CacheRunwire::current()?->runtime)->toBe($runtime)
                            ->and(CacheRunwire::current()?->request)->toBe($request)
                            ->and($bus->runwireBinding()->request())->toBe($request)
                            ->and($factory->connection()->scalar('SELECT 2'))->toBe(2);

                        return $bus->dispatch(new stdClass());
                    },
                ));
                $child->start();
                expect($child->getReturn())->toBeInstanceOf(\Infocyph\Omnibus\Envelope\Envelope::class)
                    ->and(CacheRunwire::current()?->request)->toBe($request);

                $request->cancel(CancellationReason::HOST_CANCELLED);
                expect(fn() => $connection->scalar('SELECT 3'))
                    ->toThrow(\Infocyph\DBLayer\Exceptions\ConnectionException::class)
                    ->and(fn() => $bus->dispatch(new stdClass()))->toThrow(CancelledException::class);

                return $connection;
            };
            $connection = $throughLibrary
                ? CacheRunwire::share($request, null, static fn(): mixed => $execution->run($callback), $runtime)
                : $execution->run($callback, [RuntimeContext::class => $runtime, RequestContext::class => $request]);

            expect(CacheRunwire::current())->toBeNull()
                ->and(CacheRunwire::runtime())->toBeNull()
                ->and($bus->runwireBinding()->request())->toBeNull()
                ->and($connection->runwireBinding())->toBeNull()
                ->and($request->completed())->toBeFalse();
            if ($pooled && $previousConnection !== null) {
                expect($connection)->toBe($previousConnection);
            }
            $previousConnection = $connection;
        }
        $execution->run(static function () use ($factory, $bus): void {
            expect($factory->connection()->scalar('SELECT 4'))->toBe(4)
                ->and($factory->connection()->runwireBinding())->toBeNull()
                ->and($bus->dispatch(new stdClass()))->toBeInstanceOf(\Infocyph\Omnibus\Envelope\Envelope::class);
        });
    } finally {
        foreach ($requests as $request) {
            $request->complete();
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($project);
    }
})->with([RuntimeMode::Cli, RuntimeMode::Worker, RuntimeMode::Scheduler])->with([false, true])->with([false, true])->with([false, true]);

it('isolates providers across overlapping Foundation executions and preserves exception restoration', function (): void {
    $app = Foundation::cli([
        'app' => ['capabilities' => ['messaging' => true]],
        'database' => [
            'default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
        'messaging' => ['default_route' => ['transport' => 'memory']],
    ])->boot();
    $runtime = RuntimeContext::standalone();
    $first = RequestContext::create($runtime);
    $second = RequestContext::create($runtime);
    $bus = $app->make(MessageBus::class);
    $make = static fn(RequestContext $request): Fiber => new Fiber(static function () use ($app, $bus, $runtime, $request): mixed {
        return $app->execution()->run(static function () use ($app, $bus, $request): mixed {
            $connection = $app->make(DBLayerFactory::class)->connection();
            expect($connection->runwireBinding()['request'])->toBe($request)
                ->and($bus->runwireBinding()->request())->toBe($request);
            Fiber::suspend($connection);
            if ($request->cancelled()) {
                expect(fn() => $connection->scalar('SELECT 1'))
                    ->toThrow(\Infocyph\DBLayer\Exceptions\ConnectionException::class)
                    ->and(fn() => $bus->dispatch(new stdClass()))->toThrow(CancelledException::class);

                throw new RuntimeException('primary execution failure');
            }

            return [$connection->scalar('SELECT 2'), $bus->dispatch(new stdClass())];
        }, [RuntimeContext::class => $runtime, RequestContext::class => $request]);
    });

    try {
        $left = $make($first);
        $right = $make($second);
        expect($left->start())->not->toBe($right->start());
        $first->cancel(CancellationReason::HOST_CANCELLED);
        expect(fn() => $left->resume())->toThrow(RuntimeException::class, 'primary execution failure');
        $right->resume();
        expect($right->getReturn()[0])->toBe(2)
            ->and($right->getReturn()[1])->toBeInstanceOf(\Infocyph\Omnibus\Envelope\Envelope::class)
            ->and(CacheRunwire::current())->toBeNull()
            ->and(CacheRunwire::runtime())->toBeNull()
            ->and($bus->runwireBinding()->request())->toBeNull();
    } finally {
        $first->complete();
        $second->complete();
    }
});

it('keeps the host coroutine scope in a structured child and stops native work after host cancellation', function (bool $throughLibrary): void {
    $app = Foundation::worker([
        'app' => ['capabilities' => ['messaging' => true]],
        'database' => [
            'default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
        'messaging' => ['default_route' => ['transport' => 'memory']],
    ])->boot();
    $runtime = RuntimeContext::fromCapabilities(
        new \Infocyph\Runwire\RuntimeCapabilities(
            \Infocyph\Runwire\Runtime\Enum\RuntimeDriver::NATIVE,
            runwireLoopAvailable: true,
            supportsRunwireCoroutines: true,
        ),
        'foundation-native-provider-child',
    );
    $request = RequestContext::create($runtime);
    $observed = (object) ['cancelled' => false];
    $work = static fn(): mixed => new \Infocyph\Runwire\Coroutine\CoroutineRuntime()->runRequest(
        $request,
        static function (\Infocyph\Runwire\Coroutine\CoroutineScope $scope) use ($app, $runtime, $request, $observed, $throughLibrary): mixed {
            $callback = static function () use ($app, $scope, $request, $observed): mixed {
                $captured = $app->runtime()->captureScopeContext();
                $child = $scope->spawn(static fn(): mixed => $app->execution()->withinBorrowedScope(
                    $captured,
                    static function () use ($app, $scope, $request, $observed): void {
                        $connection = $app->make(DBLayerFactory::class)->connection();
                        $bus = $app->make(MessageBus::class);
                        expect($app->make(\Infocyph\Runwire\Coroutine\CoroutineScope::class))->toBe($scope)
                            ->and($connection->runwireBinding()['scope'])->toBe($scope)
                            ->and($bus->runwireBinding()->scope())->toBe($scope)
                            ->and(CacheRunwire::current()?->scope)->toBe($scope)
                            ->and($connection->scalar('SELECT 1'))->toBe(1);
                        $request->cancel(CancellationReason::HOST_CANCELLED);
                        expect(fn() => $connection->scalar('SELECT 2'))
                            ->toThrow(\Infocyph\DBLayer\Exceptions\ConnectionException::class)
                            ->and(fn() => $bus->dispatch(new stdClass()))->toThrow(CancelledException::class);
                        $observed->cancelled = true;
                    },
                ));

                return $child->await();
            };

            return $throughLibrary
                ? CacheRunwire::share($request, $scope, static fn(): mixed => $app->execution()->run($callback), $runtime)
                : $app->execution()->run($callback, [RuntimeContext::class => $runtime, RequestContext::class => $request, \Infocyph\Runwire\Coroutine\CoroutineScope::class => $scope]);
        },
    );

    try {
        expect($work)->toThrow(CancelledException::class)
            ->and($observed->cancelled)->toBeTrue()
            ->and(CacheRunwire::current())->toBeNull()
            ->and(CacheRunwire::runtime())->toBeNull();
    } finally {
        $request->complete();
    }
})->with([false, true]);

it('cancels only a child task while consuming a native cursor and keeps its host usable', function (): void {
    $app = Foundation::worker([
        'app' => ['capabilities' => ['messaging' => true]],
        'database' => ['default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        'messaging' => ['default_route' => ['transport' => 'memory']],
    ])->boot();
    $runtime = RuntimeContext::fromCapabilities(new \Infocyph\Runwire\RuntimeCapabilities(
        \Infocyph\Runwire\Runtime\Enum\RuntimeDriver::NATIVE,
        runwireLoopAvailable: true,
        supportsRunwireCoroutines: true,
    ), 'foundation-child-cancellation');
    $request = RequestContext::create($runtime);
    $state = (object) ['entered' => false, 'continued' => false];

    try {
        new \Infocyph\Runwire\Coroutine\CoroutineRuntime()->runRequest(
            $request,
            static function (\Infocyph\Runwire\Coroutine\CoroutineScope $scope) use ($app, $runtime, $request, $state): void {
                $app->execution()->run(static function () use ($app, $scope, $request, $state): void {
                    $captured = $app->runtime()->captureScopeContext();
                    $child = $scope->spawn(static fn(): mixed => $app->execution()->withinBorrowedScope(
                        $captured,
                        static function () use ($app, $scope, $state, $request): void {
                            $connection = $app->make(DBLayerFactory::class)->connection();
                            foreach ($connection->stream('SELECT 314') as $row) {
                                expect(array_values($row))->toBe([314])
                                    ->and($connection->runwireBinding()['scope'])->toBe($scope)
                                    ->and(CacheRunwire::current()?->request)->toBe($request);
                                $state->entered = true;
                                $scope->sleep(30);
                                $state->continued = true;
                            }
                        },
                    ));
                    $until = microtime(true) + 5;
                    while (!$state->entered && microtime(true) < $until) {
                        $scope->yieldNow();
                    }
                    expect($state->entered)->toBeTrue()
                        ->and($child->cancel(CancellationReason::HOST_CANCELLED))->toBeTrue()
                        ->and(fn() => $child->await())->toThrow(CancelledException::class)
                        ->and($state->continued)->toBeFalse()
                        ->and($request->cancelled())->toBeFalse()
                        ->and($scope->cancellation()->isCancelled())->toBeFalse()
                        ->and(CacheRunwire::current()?->request)->toBe($request)
                        ->and($app->make(DBLayerFactory::class)->connection()->scalar('SELECT 1'))->toBe(1)
                        ->and($app->make(MessageBus::class)->dispatch(new stdClass()))
                        ->toBeInstanceOf(\Infocyph\Omnibus\Envelope\Envelope::class);
                }, [RuntimeContext::class => $runtime, RequestContext::class => $request, \Infocyph\Runwire\Coroutine\CoroutineScope::class => $scope]);
            },
        );
        expect($request->completed())->toBeFalse()
            ->and(CacheRunwire::current())->toBeNull()
            ->and(CacheRunwire::runtime())->toBeNull();
    } finally {
        $request->complete();
    }
});

it('rejects a shared completed or cancelled host request before starting framework work', function (string $state): void {
    $app = Foundation::cli(['_config_cache' => false, 'app' => ['capabilities' => []]])->boot();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $called = false;
    if ($state === 'completed') {
        $request->complete();
    } else {
        $request->cancel(CancellationReason::HOST_CANCELLED);
    }
    $expected = $state === 'completed' ? LogicException::class : CancelledException::class;
    $callback = static function () use (&$called): void {
        $called = true;
    };

    try {
        CacheRunwire::share($request, null, static function () use ($app, $request, $expected, $callback): void {
            expect(fn() => $app->execution()->run($callback))->toThrow($expected)
                ->and(CacheRunwire::current()?->request)->toBe($request);
        }, $runtime);
        expect($called)->toBeFalse()
            ->and($request->completed())->toBe($state === 'completed')
            ->and(CacheRunwire::current())->toBeNull();
    } finally {
        $request->complete();
    }
})->with(['completed', 'cancelled']);

it('forwards a shared runtime without manufacturing absent request or coroutine scopes', function (): void {
    $app = Foundation::worker(['_config_cache' => false, 'app' => ['capabilities' => []]])->boot();
    $runtime = RuntimeContext::standalone();

    CacheRunwire::share(null, null, static function () use ($app, $runtime): void {
        $app->execution()->run(static function () use ($app, $runtime): void {
            expect($app->make(RuntimeContext::class))->toBe($runtime)
                ->and($app->make(RequestContext::class))->toBeNull()
                ->and($app->make(\Infocyph\Runwire\Coroutine\CoroutineScope::class))->toBeNull()
                ->and(CacheRunwire::current()?->request)->toBeNull()
                ->and(CacheRunwire::current()?->scope)->toBeNull();
        });
    }, $runtime);

    expect(CacheRunwire::current())->toBeNull()
        ->and(CacheRunwire::runtime())->toBeNull();
});
