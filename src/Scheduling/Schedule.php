<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

final class Schedule
{
    /** @var list<ScheduledCommand> */
    private array $commands = [];

    public function command(string $command): ScheduledCommand
    {
        $scheduled = new ScheduledCommand($command);
        $this->commands[] = $scheduled;

        return $scheduled;
    }

    /** @return list<ScheduledCommand> */
    public function entries(): array
    {
        return $this->commands;
    }
}
