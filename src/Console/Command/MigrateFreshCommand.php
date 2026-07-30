<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;

final class MigrateFreshCommand extends AbstractDatabaseCommand
{
    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('migrate:fresh')
            ->description('Drop all user tables and rerun registered DBLayer migrations.')
            ->option(Option::value('connection')->description('Connection name; null uses database.default.'))
            ->option(Option::flag('force')->description('Explicitly authorize this destructive operation.'))
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        if (!$this->options()->bool('force')) {
            $this->io()->error('migrate:fresh requires --force.');

            return ExitCode::INVALID_USAGE;
        }

        try {
            $ran = $this->migrations()->runner($this->connection())->fresh(true);
        } catch (\Throwable $exception) {
            $this->io()->error('migrate:fresh failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report(['ran' => $ran, 'count' => count($ran)]);

        return ExitCode::SUCCESS;
    }
}
