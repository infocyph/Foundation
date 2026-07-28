<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateEventCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:event', 'Create an immutable application event.', 'UserRegistered');
    }

    protected function artifact(): string
    {
        return 'event';
    }

    protected function commandName(): string
    {
        return 'create:event';
    }
}
