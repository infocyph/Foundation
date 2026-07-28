<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\ScheduleManager;

final class ScheduleListCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ScheduleManager $schedule) {}

    public static function define(CommandDefinition $command): void
    {
        $command->name('schedule:list')->description('List configured scheduled commands.');
    }

    protected function handle(): int
    {
        $rows = [];
        foreach ($this->schedule->entries() as $entry) {
            $manifest = $entry->toManifest();
            $rows[] = [
                $entry->command(),
                $manifest['cron'],
                $manifest['timezone'],
                $entry->preventsOverlap() ? 'yes' : 'no',
                $entry->requiresSingleServer() ? 'yes' : 'no',
            ];
        }
        $this->io()->table(['Command', 'Cron', 'Timezone', 'No overlap', 'One server'], $rows);

        return ExitCode::SUCCESS;
    }
}
