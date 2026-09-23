<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use Infocyph\Omnibus\Consumer\WorkerLifecycle;

final class Omnibus26StopLifecycle implements WorkerLifecycle
{
    public function heartbeat(): void {}

    public function stopRequested(): bool
    {
        return true;
    }
}
