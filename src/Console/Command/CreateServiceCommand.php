<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateServiceCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:service', 'Create an application service.', 'Billing');
    }

    protected function artifact(): string
    {
        return 'service';
    }

    protected function commandName(): string
    {
        return 'create:service';
    }
}
