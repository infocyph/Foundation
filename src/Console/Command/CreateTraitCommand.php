<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateTraitCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:trait', 'Create an application trait.', 'FormatsMoney');
    }

    protected function artifact(): string
    {
        return 'trait';
    }

    protected function commandName(): string
    {
        return 'create:trait';
    }
}
