<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class MigrateFreshCommand extends AbstractDatabaseCommand
{
    private const string NAME = 'migrate:fresh';

    public static function define(CommandDefinition $command): void
    {
        self::defineDestructiveMigration(
            $command,
            self::NAME,
            'Drop all user tables and rerun registered DBLayer migrations.',
        );
    }

    protected function handle(): int
    {
        return $this->runDestructiveMigration(self::NAME);
    }
}
