<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

final class Schedule
{
    /** @var list<ScheduledCommand> */
    private array $commands = [];

    public function add(ScheduledCommand $command): self
    {
        $this->commands[] = $command;

        return $this;
    }

    /**
     * @throws \InvalidArgumentException
     */
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
