<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\ScheduleManager;

final class ScheduleRunCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ScheduleManager $schedule) {}

    public static function define(CommandDefinition $command): void
    {
        $command->name('schedule:run')->description('Run commands due in the current minute.');
    }

    protected function handle(): int
    {
        try {
            $runs = $this->schedule->runDue();
        } catch (\Throwable $exception) {
            $this->io()->error('schedule:run failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $failed = 0;
        foreach ($runs as $run) {
            $this->io()->status(sprintf(
                '%s: %s',
                $run->command,
                $run->skipped() ? 'skipped (already running)' : 'exit ' . $run->exitCode,
            ));
            $failed += !$run->skipped() && !$run->successful() ? 1 : 0;
        }

        return $failed === 0 ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }
}
