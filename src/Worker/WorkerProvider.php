<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Worker;

/**
 * Application-specific non-message maintenance worker.
 *
 * Queue/message workers belong to Omnibus Worker/WorkerPool. A maintenance
 * provider owns only its domain loop and must execute each bounded unit through
 * WorkerRuntime so scoped application state is reset between units.
 */
interface WorkerProvider
{
    public function run(WorkerRuntime $runtime): int;
}
