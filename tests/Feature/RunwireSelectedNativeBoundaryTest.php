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
