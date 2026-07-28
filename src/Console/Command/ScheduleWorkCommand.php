<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Console\Input\ValueType;
use Infocyph\Foundation\Console\Support\ScheduleManager;

final class ScheduleWorkCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ScheduleManager $schedule) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('schedule:work')
            ->description('Keep the scheduler active and dispatch each minute once.')
            ->option(Option::value('max-runtime')->type(ValueType::FLOAT)->default(0.0)->description(
                'Maximum supervisor lifetime in seconds; 0 means unlimited. Example: 3600.',
            ))
            ->option(Option::value('poll')->type(ValueType::FLOAT)->default(0.5)->description(
                'Clock polling interval in seconds. Example: 0.5.',
            ));
    }

    protected function handle(): int
    {
        $startedAt = microtime(true);
        $lastMinute = '';
        $maximum = $this->options()->float('max-runtime');
        $poll = $this->options()->float('poll');
        if ($maximum < 0 || $poll <= 0) {
            $this->io()->error('max-runtime cannot be negative and poll must be positive.');

            return ExitCode::INVALID_USAGE;
        }

        while ($maximum === 0.0 || microtime(true) - $startedAt < $maximum) {
            $minute = date('YmdHi');
            if ($minute !== $lastMinute) {
                $this->schedule->runDue();
                $lastMinute = $minute;
            }
            usleep((int) min(1_000_000, $poll * 1_000_000));
        }

        return ExitCode::SUCCESS;
    }
}
