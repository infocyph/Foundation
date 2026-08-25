<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\OmnibusWorkerFactory;
use Infocyph\Omnibus\Consumer\WorkerLifecycle;

it('passes Foundation lifecycle callbacks through to Omnibus 2.5 workers', function (): void {
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
