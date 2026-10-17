<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Collection\LazyCollection;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwireIntegration;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\Integration\Runwire\RunwireIntegration;
use Infocyph\Omnibus\Integration\Runwire\RunwireBinding as OmnibusRunwireBinding;
use Infocyph\OTP\Support\RunwireState;
use Infocyph\CacheLayer\Integration\Runwire\RunwireExecutionContext;
use Infocyph\ReqShield\Support\RunwireExecution;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\UID\Runtime\RunwireBinding as UidRunwireBinding;

it('preserves native coroutine runtime request and scope through structured child tasks', function (): void {
    $runtime = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            RuntimeDriver::NATIVE,
            runwireLoopAvailable: true,
            supportsRunwireCoroutines: true,
        ),
        'foundation-child',
    );
    $request = RequestContext::create($runtime);
    $builder = ContainerBuilder::create('foundation.runwire.structured.' . bin2hex(random_bytes(6)));
    RunwireIntegration::registerInputs($builder);
    $container = $builder->build();
    $integration = new RunwireIntegration($container, ownsCacheLayerLifecycle: true);
    $integration->bind($runtime);
    $database = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $omnibus = new OmnibusRunwireBinding();

    try {
        $result = (new CoroutineRuntime())->runRequest(
            $request,
            static function (CoroutineScope $scope) use (
                $runtime, $request, $integration, $database, $omnibus,
            ): array {
                return $integration->withinRequest(
                    $request,
                    $scope,
                    static function (RuntimeContainerInterface $owner) use (
                        $runtime, $request, $scope, $integration, $database, $omnibus,
                    ): array {
                        $before = CacheRunwireIntegration::current();

                        $child = $scope->spawn($integration->wrapChild(
                            $request,
                            $scope,
                            static function (RuntimeContainerInterface $active) use (
                                $runtime, $request, $scope, $database, $omnibus,
                            ): array {
                                (new UidRunwireBinding($runtime, $request, $scope))->assertActive();
                                (new RunwireExecution($runtime, $request, $scope))->checkpoint(yield: true);
                                RunwireState::checkpoint(
                                    new RunwireExecutionContext($runtime, $request, $scope),
                                );

                                $cache = CacheRunwireIntegration::current();
                                $db = $database->withRunwire(
                                    $runtime,
                                    static fn(): ?array => $database->runwireBinding(),
                                    $request,
                                    $scope,
                                );
                                $bus = $omnibus->withRunwire(
                                    $runtime,
                                    static fn(): array => [$omnibus->runtime(), $omnibus->request(), $omnibus->scope()],
                                    $request,
                                    $scope,
                                );

                                return [
                                    $active->get(RuntimeContext::class),
                                    $active->get(RequestContext::class),
                                    $active->get(CoroutineScope::class),
                                    $cache?->runtime,
                                    $cache?->request,
                                    $cache?->scope,
                                    $db,
                                    $bus,
                                    LazyCollection::from([1, 2, 3])->withRunwire($runtime, $request, $scope)->all(),
                                ];
                            },
                        ));

                        return [
                            $owner->get(RuntimeContext::class),
                            $owner->get(RequestContext::class),
                            $owner->get(CoroutineScope::class),
                            $before?->request,
                            $child->await(),
                            CacheRunwireIntegration::current()?->request,
                        ];
                    },
                );
            },
        );

        expect($result[0])->toBe($runtime)
            ->and($result[1])->toBe($request)
            ->and($result[2])->toBeInstanceOf(CoroutineScope::class)
            ->and($result[3])->toBe($request)
            ->and($result[4][0])->toBe($runtime)
            ->and($result[4][1])->toBe($request)
            ->and($result[4][2])->toBe($result[2])
            ->and($result[4][3])->toBe($runtime)
            ->and($result[4][4])->toBe($request)
            ->and($result[4][5])->toBe($result[2])
            ->and($result[4][6])->toBe([
                'runtime' => $runtime,
                'request' => $request,
                'scope' => $result[2],
            ])
            ->and($result[4][7])->toBe([$runtime, $request, $result[2]])
            ->and($result[4][8])->toBe([1, 2, 3])
            ->and($result[5])->toBe($request)
            ->and(CacheRunwireIntegration::current())->toBeNull()
            ->and($database->runwireBinding())->toBeNull()
            ->and($omnibus->runtime())->toBeNull();
    } finally {
        $integration->release($runtime);
        $request->complete();
    }

    expect(CacheRunwireIntegration::runtime())->toBeNull();
});

it('detaches a throwing native child and reuses InterMix for the next host-owned request', function (): void {
    $runtime = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            RuntimeDriver::NATIVE,
            runwireLoopAvailable: true,
            supportsRunwireCoroutines: true,
        ),
        'foundation-failed-child',
    );
    $builder = ContainerBuilder::create('foundation.runwire.failure.' . bin2hex(random_bytes(6)));
    RunwireIntegration::registerInputs($builder);
    $container = $builder->build();
    $integration = new RunwireIntegration($container, ownsCacheLayerLifecycle: true);
    $integration->bind($runtime);
    $coroutines = new CoroutineRuntime();
    $failedRequest = RequestContext::create($runtime);
    $nextRequest = null;

    try {
        expect(fn(): mixed => $coroutines->runRequest(
            $failedRequest,
            static function (CoroutineScope $scope) use ($integration, $failedRequest): mixed {
                return $integration->withinRequest(
                    $failedRequest,
                    $scope,
                    static function (RuntimeContainerInterface $owner) use (
                        $scope,
                        $integration,
                        $failedRequest,
                    ): mixed {
                        expect($owner->get(RequestContext::class))->toBe($failedRequest);
                        $child = $scope->spawn($integration->wrapChild(
                            $failedRequest,
                            $scope,
                            static function (RuntimeContainerInterface $borrowed) use ($failedRequest): never {
                                expect($borrowed->get(RequestContext::class))->toBe($failedRequest);

                                throw new DomainException('structured child failure');
                            },
                        ));

                        return $child->await();
                    },
                );
            },
        ))->toThrow(DomainException::class, 'structured child failure');

        expect(CacheRunwireIntegration::current())->toBeNull();

        $nextRequest = RequestContext::create($runtime);
        $result = $coroutines->runRequest(
            $nextRequest,
            static function (CoroutineScope $scope) use ($integration, $nextRequest): array {
                return $integration->withinRequest(
                    $nextRequest,
                    $scope,
                    static fn(RuntimeContainerInterface $owner): array => [
                        $owner->get(RequestContext::class),
                        CacheRunwireIntegration::current()?->request,
                    ],
                );
            },
        );

        expect($result)->toBe([$nextRequest, $nextRequest])
            ->and(CacheRunwireIntegration::current())->toBeNull();
    } finally {
        $integration->release($runtime);
        $failedRequest->complete();
        $nextRequest?->complete();
    }

    expect(CacheRunwireIntegration::runtime())->toBeNull();
});
