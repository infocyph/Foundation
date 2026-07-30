<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Console\Input\ValueType;

final class MigrateRollbackCommand extends AbstractDatabaseCommand
{
    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('migrate:rollback')
            ->description('Roll back recent DBLayer migration batches.')
            ->option(Option::value('connection')->description('Connection name; null uses database.default.'))
            ->option(
                Option::value('batches')
                    ->type(ValueType::INTEGER)
                    ->default(1)
                    ->description('Positive number of recent batches; example: 1.'),
            )
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        $batches = $this->options()->int('batches');
        if ($batches < 1) {
            $this->io()->error('migrate:rollback --batches must be positive.');

            return ExitCode::INVALID_USAGE;
        }

        try {
            $rolledBack = $this->migrations()->runner($this->connection())->rollback($batches);
        } catch (\Throwable $exception) {
            $this->io()->error('migrate:rollback failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report(['rolled_back' => $rolledBack, 'count' => count($rolledBack)]);

        return ExitCode::SUCCESS;
    }
}
