<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\ConsumerFactory;
use Infocyph\Foundation\Messaging\OmnibusWorkerFactory;
use Infocyph\Omnibus\Consumer\WorkerLifecycle;
use Infocyph\Omnibus\Consumer\WorkerPool;

it('passes Foundation lifecycle callbacks through to Omnibus 2.6 workers', function (): void {
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
    $app = Foundation::worker([
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
    $repository = $app->container()->getRepository();

    expect($repository->hasResolvedSingleton(ConsumerFactory::class))->toBeFalse();

    $factory = $app->make(OmnibusWorkerFactory::class);
    expect($factory->transport('cold'))->toBe('memory')
        ->and($factory->pool('cold')['enabled'])->toBeFalse()
        ->and($repository->hasResolvedSingleton(ConsumerFactory::class))->toBeFalse();

    $factory->make('cold');
    expect($repository->hasResolvedSingleton(ConsumerFactory::class))->toBeTrue();
});

it('passes parent lifecycle directly to Omnibus 2.6 WorkerPool before child spawn', function (): void {
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
