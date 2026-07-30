<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;

final class MigrateCommand extends AbstractDatabaseCommand
{
    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('migrate')
            ->description('Run pending DBLayer migrations from the explicit manifest.')
            ->option(Option::value('connection')->description('Connection name; null uses database.default.'))
            ->option(Option::flag('step')->description('Place each migration in its own batch.'))
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        try {
            $ran = $this->migrations()->runner($this->connection())->run(
                $this->options()->bool('step'),
            );
        } catch (\Throwable $exception) {
            $this->io()->error('migrate failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report(['ran' => $ran, 'count' => count($ran)]);

        return ExitCode::SUCCESS;
    }
}
