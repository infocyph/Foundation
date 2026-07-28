<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console;

use Infocyph\Console\Worker\WorkerOptions;
use Infocyph\Console\Worker\WorkloadProbe;

interface WorkerProvider
{
    /** @return list<string> */
    public function command(): array;

    public function options(): WorkerOptions;

    public function workload(): WorkloadProbe;
}
