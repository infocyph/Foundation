<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateProviderCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:provider', 'Create an application service provider.', 'Billing');
    }

    protected function artifact(): string
    {
        return 'provider';
    }

    protected function commandName(): string
    {
        return 'create:provider';
    }
}
