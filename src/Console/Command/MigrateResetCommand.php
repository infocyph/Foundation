<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class MigrateResetCommand extends AbstractDatabaseCommand
{
    private const string NAME = 'migrate:reset';

    public static function define(CommandDefinition $command): void
    {
        self::defineDestructiveMigration(
            $command,
            self::NAME,
            'Roll back every applied registered DBLayer migration.',
        );
    }

    protected function handle(): int
    {
        return $this->runDestructiveMigration(self::NAME);
    }
}
