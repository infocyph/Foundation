<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

it('forwards host Runwire inputs through worker CLI and scheduler owned and borrowed scopes', function (): void {
    foreach (['worker', 'cli', 'scheduler'] as $mode) {
        $config = [
            'app' => [
                'env' => 'testing',
                'capabilities' => ['runwire' => true],
            ],
        ];
        $app = match ($mode) {
            'worker' => Foundation::worker($config)->boot(),
            'cli' => Foundation::cli($config)->boot(),
            'scheduler' => Foundation::scheduler($config)->boot(),
        };
        $runtime = RuntimeContext::standalone();
        $request = RequestContext::create($runtime);

        try {
            $result = $app->execution()->run(
                static function (ExecutionId $id) use ($app): array {
                    $host = $app->make(RuntimeContext::class);
                    $request = $app->make(RequestContext::class);
                    $scope = $app->runtime()->captureScopeContext();
                    $fiber = new Fiber(static fn(): array => $app->execution()->withinBorrowedScope(
                        $scope,
                        static fn(ExecutionId $borrowed): array => [
                            $app->make(RuntimeContext::class),
                            $app->make(RequestContext::class),
                            $borrowed,
                        ],
                    ));
                    $fiber->start();

                    return [$host, $request, $id, $fiber->getReturn()];
                },
                seeds: [
                    RuntimeContext::class => $runtime,
                    RequestContext::class => $request,
                ],
                executionId: new ExecutionId('native-' . $mode),
            );

            expect($result[0])->toBe($runtime)
                ->and($result[1])->toBe($request)
                ->and($result[2])->toBe($result[3][2])
                ->and($result[3][0])->toBe($runtime)
                ->and($result[3][1])->toBe($request);
        } finally {
            $request->complete();
        }
    }
});

it('rejects missing stale mismatched and cancelled host Runwire worker seeds before execution', function (): void {
    $app = Foundation::worker([
        'app' => [
            'env' => 'testing',
            'capabilities' => ['runwire' => true],
        ],
    ])->boot();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $otherRequest = RequestContext::create(RuntimeContext::standalone());
    $badPid = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(RuntimeDriver::NATIVE),
        'stale-host',
        pid: (int) getmypid() + 1,
    );
    $executed = false;
    $work = static function () use (&$executed): void {
        $executed = true;
    };

    try {
        expect(fn() => $app->execution()->run(
            $work,
            seeds: [RequestContext::class => $request],
        ))->toThrow(LogicException::class, 'host-owned runtime')
            ->and(fn() => $app->execution()->run(
                $work,
                seeds: [RuntimeContext::class => $badPid],
            ))->toThrow(LogicException::class, 'different process')
            ->and(fn() => $app->execution()->run(
                $work,
                seeds: [
                    RuntimeContext::class => $runtime,
                    RequestContext::class => $otherRequest,
                ],
            ))->toThrow(LogicException::class, 'different runtime')
            ->and($executed)->toBeFalse();

        $request->cancel(CancellationReason::HOST_CANCELLED);
        expect(fn() => $app->execution()->run(
            $work,
            seeds: [
                RuntimeContext::class => $runtime,
                RequestContext::class => $request,
            ],
        ))->toThrow(\Infocyph\Runwire\Exception\CancelledException::class)
            ->and($executed)->toBeFalse();
    } finally {
        $request->complete();
        $otherRequest->complete();
    }
});
