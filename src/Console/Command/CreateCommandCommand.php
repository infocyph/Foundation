<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateCommandCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:command', 'Create an application console command.', 'Reports/Daily');
    }

    protected function artifact(): string
    {
        return 'command';
    }

    protected function commandName(): string
    {
        return 'create:command';
    }
}
