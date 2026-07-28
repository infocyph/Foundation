<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\ScheduleManager;

final class ScheduleClearCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ScheduleManager $schedule) {}

    public static function define(CommandDefinition $command): void
    {
        $command->name('schedule:clear')->description('Remove the compiled schedule manifest.');
    }

    protected function handle(): int
    {
        $removed = $this->schedule->clear();
        $this->io()->success($removed ? 'Schedule cache cleared.' : 'No schedule cache exists.');

        return ExitCode::SUCCESS;
    }
}
