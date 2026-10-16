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

it('forwards published native providers through compiled and borrowed execution scopes and recycled leases', function (RuntimeMode $mode, bool $pooled): void {
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
        $report = new GeneratedRuntimeCompiler()->compile($config, $mode, $artifact, $capabilities);
        $generated = GeneratedRuntime::loadPrevalidated(
            $config, $mode, $artifact, $report['metadata_sha256'], $report['digest'], $capabilities,
        );
        $app = $generated->application;
        $bus = $app->make(MessageBus::class);
        $factory = $app->make(DBLayerFactory::class);
        $execution = $app->execution();
        $previousConnection = null;

        for ($index = 0; $index < 2; $index++) {
            $request = RequestContext::create($runtime);
            $requests[] = $request;
            $connection = $execution->run(static function () use ($app, $execution, $factory, $bus, $runtime, $request): mixed {
                $connection = $factory->connection();
                expect($connection->scalar('SELECT 1'))->toBe(1)
                    ->and($connection->runwireBinding()['request'])->toBe($request)
                    ->and($bus->runwireBinding()->request())->toBe($request);
                $captured = $app->runtime()->captureScopeContext();
                $child = new Fiber(static fn(): mixed => $execution->withinBorrowedScope(
                    $captured,
                    static function () use ($factory, $bus, $runtime, $request): mixed {
                        expect(CacheRunwire::current()?->runtime)->toBe($runtime)
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
            }, [RuntimeContext::class => $runtime, RequestContext::class => $request]);

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
})->with([RuntimeMode::Cli, RuntimeMode::Worker, RuntimeMode::Scheduler])->with([false, true]);

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

it('keeps the host coroutine scope in a structured child and stops native work after host cancellation', function (): void {
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
        static function (\Infocyph\Runwire\Coroutine\CoroutineScope $scope) use ($app, $runtime, $request, $observed): mixed {
            return $app->execution()->run(static function () use ($app, $scope, $request, $observed): mixed {
                $captured = $app->runtime()->captureScopeContext();
                $child = $scope->spawn(static fn(): mixed => $app->execution()->withinBorrowedScope(
                    $captured,
                    static function () use ($app, $scope, $request, $observed): void {
                        $connection = $app->make(DBLayerFactory::class)->connection();
                        $bus = $app->make(MessageBus::class);
                        expect($connection->runwireBinding()['scope'])->toBe($scope)
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
            }, [RuntimeContext::class => $runtime, RequestContext::class => $request, \Infocyph\Runwire\Coroutine\CoroutineScope::class => $scope]);
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
});

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
