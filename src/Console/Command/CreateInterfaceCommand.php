<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateInterfaceCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:interface', 'Create an application contract.', 'BillingGateway');
    }

    protected function artifact(): string
    {
        return 'interface';
    }

    protected function commandName(): string
    {
        return 'create:interface';
    }
}
