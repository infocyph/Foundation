<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

final readonly class ScheduleRun
{
    public function __construct(
        public ScheduledCommand $entry,
        public int $exitCode,
        public bool $locked = false,
    ) {}

    public function successful(): bool
    {
        return !$this->locked && $this->exitCode === 0;
    }
}
