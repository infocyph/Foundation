<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Collection\LazyCollection;
use Infocyph\CacheLayer\Integration\Runwire\RunwireExecutionContext as CacheRunwireContext;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Epicrypt\Integration\Runwire\RunwireExecutionContext as EpicryptRunwireContext;
use Infocyph\InterMix\Integration\Runwire\RunwireIntegration as InterMixRunwire;
use Infocyph\Omnibus\Integration\Runwire\RunwireBinding as OmnibusRunwire;
use Infocyph\OTP\Support\RunwireState as OtpRunwireState;
use Infocyph\Pathwise\Integration\Runwire\RunwireExecutionContext as PathwiseRunwireContext;
use Infocyph\ReqShield\Support\RunwireExecution as ReqShieldRunwire;
use Infocyph\ReqShield\Validator;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Integration\Runwire\RunwireBinding as TalkingBytesRunwire;
use Infocyph\UID\Runtime\RunwireBinding as UidRunwire;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;

it('pins the selected published native Runwire APIs across all Foundation library owners', function (): void {
    foreach ([
        LazyCollection::class => 'withRunwire',
        CacheRunwire::class => 'share',
        Connection::class => 'withRunwire',
        EpicryptRunwireContext::class => 'checkpoint',
        InterMixRunwire::class => 'withinRequest',
        OmnibusRunwire::class => 'withRunwire',
        OtpRunwireState::class => 'checkpoint',
        PathwiseRunwireContext::class => 'checkpoint',
        Validator::class => 'validateWithRunwire',
        TalkingBytesRunwire::class => 'deadline',
        UidRunwire::class => 'assertActive',
        RunwireRuntimeAdapter::class => 'context',
    ] as $owner => $method) {
        expect(class_exists($owner))->toBeTrue()
            ->and(method_exists($owner, $method))->toBeTrue();
    }

    $dbRuntime = (new ReflectionMethod(Connection::class, 'withRunwire'))->getParameters()[0]->getType();
    expect($dbRuntime)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($dbRuntime->getName())->toBe(RuntimeContext::class);
});

it('forwards one exact Runwire runtime and request into native library boundaries', function (): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);

    $array = LazyCollection::from([1, 2])->withRunwire($runtime, $request);
    $epicrypt = new EpicryptRunwireContext($runtime, $request);
    $pathwise = new PathwiseRunwireContext($runtime, $request);
    $reqshield = new ReqShieldRunwire($runtime, $request);
    $uid = new UidRunwire($runtime, $request);
    $talkingBytes = new TalkingBytesRunwire($runtime, $request);
    $omnibus = new OmnibusRunwire();
    $otp = new CacheRunwireContext($runtime, $request);

    try {
        expect($array->all())->toBe([1, 2])
            ->and($uid->runtime)->toBe($runtime)
            ->and($uid->request)->toBe($request)
            ->and($pathwise->runtime)->toBe($runtime)
            ->and($pathwise->request)->toBe($request)
            ->and($epicrypt->runtime)->toBe($runtime)
            ->and($epicrypt->request)->toBe($request);

        $epicrypt->checkpoint();
        $pathwise->checkpoint();
        $uid->assertActive();
        $reqshield->checkpoint();
        OtpRunwireState::checkpoint($otp);

        expect($talkingBytes->deadline())->toBeNull()
            ->and($omnibus->withRunwire(
                $runtime,
                static fn(): array => [$omnibus->runtime(), $omnibus->request()],
                $request,
            ))->toBe([$runtime, $request])
            ->and($omnibus->runtime())->toBeNull();
    } finally {
        $request->complete();
    }

    expect(fn() => $epicrypt->checkpoint())->toThrow(LogicException::class)
        ->and(fn() => $pathwise->checkpoint())->toThrow(LogicException::class)
        ->and(fn() => $uid->assertActive())->toThrow(LogicException::class)
        ->and(fn() => $reqshield->checkpoint())->toThrow(LogicException::class);
});

it('forwards exact Runwire identities through native intermediary and borrowed child scopes into selected libraries', function (): void {
    $builder = \Infocyph\InterMix\DI\ContainerBuilder::create('foundation.runwire.child.' . bin2hex(random_bytes(6)));
    InterMixRunwire::registerInputs($builder);
    $container = $builder->build();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $bridge = new InterMixRunwire($container);
    $connection = new \Infocyph\DBLayer\Connection\Connection(
        \Infocyph\DBLayer\Connection\ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]),
    );
    $omnibus = new OmnibusRunwire();
    CacheRunwire::bind($runtime);
    $bridge->bind($runtime);

    try {
        $captured = null;
        $values = $bridge->withinRequest(
            $request,
            null,
            static function (\Infocyph\InterMix\DI\RuntimeContainerInterface $owner) use (
                $bridge, $runtime, $request, $connection, $omnibus, &$captured,
            ): array {
                $captured = $owner->captureScopeContext();
                $parent = [$owner->get(RuntimeContext::class), $owner->get(RequestContext::class)];
                $parentCache = CacheRunwire::current();

                $intermediary = static function () use (
                    $bridge, $captured, $runtime, $request, $connection, $omnibus,
                ): array {
                    $fiber = new Fiber(static function () use (
                        $bridge, $captured, $runtime, $request, $connection, $omnibus,
                    ): array {
                        return $bridge->withinScopeContext(
                            $captured,
                            $request,
                            null,
                            static function (\Infocyph\InterMix\DI\RuntimeContainerInterface $child) use (
                                $runtime, $request, $connection, $omnibus,
                            ): array {
                                $epicrypt = new EpicryptRunwireContext($runtime, $request);
                                $pathwise = new PathwiseRunwireContext($runtime, $request);
                                $reqshield = new ReqShieldRunwire($runtime, $request);
                                $uid = new UidRunwire($runtime, $request);
                                $epicrypt->checkpoint();
                                $pathwise->checkpoint();
                                $reqshield->checkpoint();
                                $uid->assertActive();
                                OtpRunwireState::checkpoint(new CacheRunwireContext($runtime, $request));

                                $database = $connection->withRunwire(
                                    $runtime,
                                    static fn(): ?array => $connection->runwireBinding(),
                                    $request,
                                );
                                $messaging = $omnibus->withRunwire(
                                    $runtime,
                                    static fn(): array => [$omnibus->runtime(), $omnibus->request()],
                                    $request,
                                );

                                return [
                                    $child->get(RuntimeContext::class),
                                    $child->get(RequestContext::class),
                                    CacheRunwire::current()?->runtime,
                                    CacheRunwire::current()?->request,
                                    $database,
                                    $messaging,
                                    LazyCollection::from([10, 20])->withRunwire($runtime, $request)->all(),
                                    (new TalkingBytesRunwire($runtime, $request))->deadline(),
                                ];
                            },
                        );
                    });
                    $fiber->start();

                    return $fiber->getReturn();
                };

                return [$parent, $parentCache, $intermediary(), CacheRunwire::current()];
            },
        );

        [$parent, $parentCache, $child, $restoredCache] = $values;
        expect($parent)->toBe([$runtime, $request])
            ->and($parentCache?->runtime)->toBe($runtime)
            ->and($parentCache?->request)->toBe($request)
            ->and($child[0])->toBe($runtime)
            ->and($child[1])->toBe($request)
            ->and($child[2])->toBe($runtime)
            ->and($child[3])->toBe($request)
            ->and($child[4])->toBe([
                'runtime' => $runtime,
                'request' => $request,
                'scope' => null,
            ])
            ->and($child[5])->toBe([$runtime, $request])
            ->and($child[6])->toBe([10, 20])
            ->and($child[7])->toBeNull()
            ->and($restoredCache?->request)->toBe($request)
            ->and(CacheRunwire::current())->toBeNull()
            ->and($omnibus->runtime())->toBeNull()
            ->and($connection->runwireBinding())->toBeNull();

        expect(fn(): mixed => $bridge->withinScopeContext(
            $captured,
            $request,
            null,
            static fn(): string => 'stale-child',
        ))->toThrow(\Infocyph\InterMix\Exceptions\ContainerException::class);
    } finally {
        $bridge->release($runtime);
        CacheRunwire::release($runtime);
        $request->complete();
    }
});

it('rejects host cancellation at the native intermediary and child boundaries', function (): void {
    $builder = \Infocyph\InterMix\DI\ContainerBuilder::create('foundation.runwire.cancel.' . bin2hex(random_bytes(6)));
    InterMixRunwire::registerInputs($builder);
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $bridge = new InterMixRunwire($builder->build());
    $bridge->bind($runtime);
    try {
        $request->cancel(\Infocyph\Runwire\Runtime\Enum\CancellationReason::HOST_CANCELLED);
        expect($request->cancelled())->toBeTrue()
            ->and(fn(): mixed => $request->cancellation->throwIfCancelled())
            ->toThrow(\Infocyph\Runwire\Exception\CancelledException::class)
            ->and(fn(): mixed => $bridge->withinRequest(
                $request,
                null,
                static function () use ($runtime, $request): void {
                    (new ReqShieldRunwire($runtime, $request))->checkpoint();
                },
            ))->toThrow(\Infocyph\Runwire\Exception\CancelledException::class)
            ->and(fn(): mixed => (new ReqShieldRunwire($runtime, $request))->checkpoint())
            ->toThrow(\Infocyph\Runwire\Exception\CancelledException::class)
            ->and(fn(): mixed => (new UidRunwire($runtime, $request))->assertActive())
            ->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
    } finally {
        $bridge->release($runtime);
        $request->complete();
    }
});
