<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateExceptionCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:exception', 'Create an application runtime exception.', 'BillingFailed');
    }

    protected function artifact(): string
    {
        return 'exception';
    }

    protected function commandName(): string
    {
        return 'create:exception';
    }
}
