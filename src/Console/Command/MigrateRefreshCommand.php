<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;

final class MigrateRefreshCommand extends AbstractDatabaseCommand
{
    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('migrate:refresh')
            ->description('Roll back and rerun every registered DBLayer migration.')
            ->option(Option::value('connection')->description('Connection name; null uses database.default.'))
            ->option(Option::flag('force')->description('Explicitly authorize this destructive operation.'))
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        if (!$this->options()->bool('force')) {
            $this->io()->error('migrate:refresh requires --force.');

            return ExitCode::INVALID_USAGE;
        }

        try {
            $ran = $this->migrations()->runner($this->connection())->refresh(true);
        } catch (\Throwable $exception) {
            $this->io()->error('migrate:refresh failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report(['ran' => $ran, 'count' => count($ran)]);

        return ExitCode::SUCCESS;
    }
}
