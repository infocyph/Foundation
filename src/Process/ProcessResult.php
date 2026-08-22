<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
        public bool $timedOut = false,
        public ProcessTerminationReason $reason = ProcessTerminationReason::Exited,
        public ?int $signal = null,
        public int $durationNanoseconds = 0,
    ) {}

    public function cancelled(): bool
    {
        return $this->reason === ProcessTerminationReason::Cancelled;
    }

    public function interrupted(): bool
    {
        return $this->reason === ProcessTerminationReason::Interrupted;
    }

    public function successful(): bool
    {
        return $this->exitCode === 0 && $this->reason === ProcessTerminationReason::Exited;
    }

    public function terminatedBySignal(): bool
    {
        return $this->signal !== null;
    }
}
