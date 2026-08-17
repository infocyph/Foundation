<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Worker;

interface WorkerProvider
{
    /**
     * Own the worker-specific loop/consumer while Foundation supplies runtime scoping and lifecycle.
     */
    public function run(WorkerRuntime $runtime): int;
}
