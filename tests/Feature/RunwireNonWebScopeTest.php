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

        $request->complete();
        expect(fn() => $app->execution()->run(
            $work,
            seeds: [
                RuntimeContext::class => $runtime,
                RequestContext::class => $request,
            ],
        ))->toThrow(LogicException::class, 'completed')
            ->and($executed)->toBeFalse();
    } finally {
        $request->complete();
        $otherRequest->complete();
    }
});

it('keeps exact host Runwire identities in source-free compiled worker CLI and scheduler releases', function (): void {
    $project = sys_get_temp_dir() . '/foundation-runwire-generated-' . bin2hex(random_bytes(6));
    mkdir($project . '/bootstrap/cache', 0777, true);
    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
            'capabilities' => ['runwire' => true],
        ],
        '_config_cache' => false,
    ];

    try {
        $compiler = new \Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler();

        foreach ([
            \Infocyph\Foundation\Application\RuntimeMode::Worker,
            \Infocyph\Foundation\Application\RuntimeMode::Cli,
            \Infocyph\Foundation\Application\RuntimeMode::Scheduler,
        ] as $mode) {
            $artifact = $project . '/bootstrap/cache/runwire-' . $mode->value . '.php';
            $report = $compiler->compile($config, $mode, $artifact, ['runwire']);
            expect($report['skipped'])->toBe([])
                ->and($report['capabilities']['runwire'] ?? null)->toBeTrue();

            $generated = \Infocyph\Foundation\Runtime\GeneratedRuntime::loadPrevalidated(
                $config,
                $mode,
                $artifact,
                $report['metadata_sha256'],
                $report['digest'],
                ['runwire'],
            );
            $nativeRuntime = RuntimeContext::standalone();
            $nativeRequest = RequestContext::create($nativeRuntime);

            try {
                // Resolve the execution entry on the owner carrier before
                // attaching the borrowed scope in a child Fiber. A compiled
                // production fallback may initialize its own scope on first use.
                $execution = $generated->application->execution();
                $result = $execution->run(
                    static function (ExecutionId $id) use ($generated, $execution): array {
                        $runtime = $generated->application->make(RuntimeContext::class);
                        $request = $generated->application->make(RequestContext::class);
                        $context = $generated->container->captureScopeContext();
                        $child = new Fiber(static fn(): array => $execution->withinBorrowedScope(
                                $context,
                                static fn(ExecutionId $borrowed): array => [
                                    $borrowed,
                                    $generated->application->make(RuntimeContext::class),
                                    $generated->application->make(RequestContext::class),
                                ],
                            ));
                        $child->start();

                        return [$id, $runtime, $request, $child->getReturn()];
                    },
                    [
                        RuntimeContext::class => $nativeRuntime,
                        RequestContext::class => $nativeRequest,
                    ],
                    new ExecutionId('compiled-' . $mode->value),
                );

                expect($result[1])->toBe($nativeRuntime)
                    ->and($result[2])->toBe($nativeRequest)
                    ->and($result[3])->toBe([$result[0], $nativeRuntime, $nativeRequest]);
            } finally {
                $nativeRequest->complete();
            }
        }
    } finally {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($project);
    }
});
