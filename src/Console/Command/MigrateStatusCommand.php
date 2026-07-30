<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;

final class MigrateStatusCommand extends AbstractDatabaseCommand
{
    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('migrate:status')
            ->description('Show registered and applied DBLayer migrations.')
            ->option(Option::value('connection')->description('Connection name; null uses database.default.'))
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        try {
            $status = $this->migrations()->runner($this->connection())->status();
        } catch (\Throwable $exception) {
            $this->io()->error('migrate:status failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report(['migrations' => $status]);

        return ExitCode::SUCCESS;
    }
}
