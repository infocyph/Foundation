<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class MigrateRefreshCommand extends AbstractDatabaseCommand
{
    private const string NAME = 'migrate:refresh';

    public static function define(CommandDefinition $command): void
    {
        self::defineDestructiveMigration(
            $command,
            self::NAME,
            'Roll back and rerun every registered DBLayer migration.',
        );
    }

    protected function handle(): int
    {
        return $this->runDestructiveMigration(self::NAME);
    }
}
