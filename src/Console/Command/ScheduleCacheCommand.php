<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\ScheduleManager;

final class ScheduleCacheCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ScheduleManager $schedule) {}

    public static function define(CommandDefinition $command): void
    {
        $command->name('schedule:cache')->description('Compile scheduled command metadata.');
    }

    protected function handle(): int
    {
        try {
            $path = $this->schedule->write();
        } catch (\Throwable $exception) {
            $this->io()->error('schedule:cache failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }
        $this->io()->success('Schedule manifest ready at: ' . $path);

        return ExitCode::SUCCESS;
    }
}
