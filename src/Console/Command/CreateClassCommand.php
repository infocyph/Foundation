<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateClassCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:class', 'Create a plain application class.', 'Services/ReportBuilder');
    }

    protected function artifact(): string
    {
        return 'class';
    }

    protected function commandName(): string
    {
        return 'create:class';
    }
}
