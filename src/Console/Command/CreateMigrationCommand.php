<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateMigrationCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure(
            $command,
            'create:migration',
            'Create an explicitly registered DBLayer migration.',
            'CreateUsers',
        );
    }

    protected function artifact(): string
    {
        return 'migration';
    }

    protected function commandName(): string
    {
        return 'create:migration';
    }
}
