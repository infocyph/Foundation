<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateSeederCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure(
            $command,
            'create:seeder',
            'Create an explicitly registered DBLayer seeder.',
            'Production',
        );
    }

    protected function artifact(): string
    {
        return 'seeder';
    }

    protected function commandName(): string
    {
        return 'create:seeder';
    }
}
