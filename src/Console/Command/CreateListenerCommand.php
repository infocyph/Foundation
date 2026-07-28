<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateListenerCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:listener', 'Create an invokable application event listener.', 'SendWelcomeEmail');
    }

    protected function artifact(): string
    {
        return 'listener';
    }

    protected function commandName(): string
    {
        return 'create:listener';
    }
}
