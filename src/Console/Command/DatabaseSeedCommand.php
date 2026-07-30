<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;

final class DatabaseSeedCommand extends AbstractDatabaseCommand
{
    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('db:seed')
            ->description('Run the explicit DBLayer seeder manifest.')
            ->option(Option::value('connection')->description('Connection name; null uses database.default.'))
            ->option(
                Option::flag('transaction')
                    ->negatable()
                    ->default(true)
                    ->description('Wrap seeders in one transaction; use --no-transaction to disable.'),
            )
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        try {
            $count = $this->migrations()->seed(
                $this->connection(),
                $this->options()->bool('transaction'),
            );
        } catch (\Throwable $exception) {
            $this->io()->error('db:seed failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report(['seeded' => $count]);

        return ExitCode::SUCCESS;
    }
}
