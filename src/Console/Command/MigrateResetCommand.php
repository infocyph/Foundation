<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;

final class MigrateResetCommand extends AbstractDatabaseCommand
{
    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('migrate:reset')
            ->description('Roll back every applied registered DBLayer migration.')
            ->option(Option::value('connection')->description('Connection name; null uses database.default.'))
            ->option(Option::flag('force')->description('Explicitly authorize this destructive operation.'))
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        if (!$this->options()->bool('force')) {
            $this->io()->error('migrate:reset requires --force.');

            return ExitCode::INVALID_USAGE;
        }

        try {
            $rolledBack = $this->migrations()->runner($this->connection())->reset(true);
        } catch (\Throwable $exception) {
            $this->io()->error('migrate:reset failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report(['rolled_back' => $rolledBack, 'count' => count($rolledBack)]);

        return ExitCode::SUCCESS;
    }
}
