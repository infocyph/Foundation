<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\ConsumerFactory;
use Infocyph\Foundation\Messaging\MessagingGraphFactory;
use Infocyph\Foundation\Messaging\OmnibusWorkerFactory;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Omnibus\Consumer\WorkerLifecycle;
use Infocyph\Omnibus\Consumer\WorkerPool;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;

it('passes Foundation lifecycle callbacks through to Omnibus 3 workers', function (): void {
    $app = Foundation::worker([
        'messaging' => [
            'workers' => [
                'lifecycle' => [
                    'transport' => 'memory',
                    'queue' => 'default',
                    'prefetch' => 1,
                    'visibility_seconds' => 60.0,
                    'idle_sleep_seconds' => 0.0,
                    'max_idle_sleep_seconds' => 0.0,
                    'idle_jitter_ratio' => 0.0,
                    'handle_signals' => false,
                    'pool' => ['enabled' => false],
                ],
            ],
        ],
    ]);

    $lifecycle = new class implements WorkerLifecycle {
        public int $heartbeats = 0;

        public int $stopChecks = 0;

        public function heartbeat(): void
        {
            $this->heartbeats++;
        }

        public function stopRequested(): bool
        {
            $this->stopChecks++;

            return true;
        }
    };

    $app->make(OmnibusWorkerFactory::class)->make('lifecycle', $lifecycle)->run();

    expect($lifecycle->heartbeats)->toBeGreaterThanOrEqual(1)
        ->and($lifecycle->stopChecks)->toBeGreaterThanOrEqual(1);
});


it('keeps Omnibus worker configuration reads cold until a worker is constructed', function (): void {
    $observer = new class extends ServiceProvider {
        public bool $resolved = false;

        public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
        {
            unset($context);
            $builder->onResolved(ConsumerFactory::class, function (): void {
                $this->resolved = true;
            });
        }
    };
    $app = Foundation::worker([
        'providers' => ['common' => [$observer]],
        'messaging' => [
            'workers' => [
                'cold' => [
                    'transport' => 'memory',
                    'queue' => 'default',
                    'pool' => ['enabled' => false],
                ],
            ],
        ],
    ]);
    expect($observer->resolved)->toBeFalse();

    $factory = $app->make(OmnibusWorkerFactory::class);
    expect($factory->transport('cold'))->toBe('memory')
        ->and($factory->pool('cold')['enabled'])->toBeFalse()
        ->and($observer->resolved)->toBeFalse();

    $factory->make('cold');
    expect($observer->resolved)->toBeTrue();
});

it('passes parent lifecycle directly to Omnibus 3 WorkerPool before child spawn', function (): void {
    $lifecycle = new class implements WorkerLifecycle {
        public int $heartbeats = 0;

        public function heartbeat(): void
        {
            $this->heartbeats++;
        }

        public function stopRequested(): bool
        {
            return true;
        }
    };

    $pool = new WorkerPool(
        workerFactory: static fn(int $slot): never => throw new RuntimeException(
            'worker factory must not run when lifecycle requests stop before spawn: ' . $slot,
        ),
        lifecycle: $lifecycle,
        lifecycleIntervalSeconds: 0.01,
    );

    $pool->run();

    expect($lifecycle->heartbeats)->toBeGreaterThanOrEqual(1);
});

it('resolves Omnibus Runwire host ownership at operation time without retaining prior requests', function (): void {
    $binding = MessagingGraphFactory::runwireBinding();

    expect($binding->runtime())->toBeNull()
        ->and($binding->request())->toBeNull();

    $runtime = RuntimeContext::standalone();
    CacheRunwire::bind($runtime);

    try {
        $first = RequestContext::create($runtime);
        try {
            CacheRunwire::share($first, null, static function () use ($binding, $runtime, $first): void {
                expect($binding->runtime())->toBe($runtime)
                    ->and($binding->request())->toBe($first)
                    ->and($binding->run(static fn(): string => 'handled'))->toBe('handled');

                $first->cancel(CancellationReason::HOST_CANCELLED);
                expect($binding->isCancellationRequested())->toBeTrue()
                    ->and(fn() => $binding->checkpoint())->toThrow(CancelledException::class)
                    ->and(fn() => $binding->run(static fn(): string => 'must-not-run'))
                    ->toThrow(CancelledException::class);
            }, $runtime);
        } finally {
            $first->complete();
        }

        expect($binding->request())->toBeNull();

        $second = RequestContext::create($runtime);
        try {
            CacheRunwire::share($second, null, static function () use ($binding, $runtime, $second): void {
                expect($binding->runtime())->toBe($runtime)
                    ->and($binding->request())->toBe($second)
                    ->and($binding->run(static fn(): string => 'reused'))->toBe('reused');
            }, $runtime);
        } finally {
            $second->complete();
        }
    } finally {
        CacheRunwire::release($runtime);
    }

    expect($binding->runtime())->toBeNull()
        ->and($binding->request())->toBeNull()
        ->and($binding->run(static fn(): string => 'ordinary'))->toBe('ordinary');
});
