<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateControllerCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:controller', 'Create an HTTP controller.', 'Admin/User');
    }

    protected function artifact(): string
    {
        return 'controller';
    }

    protected function commandName(): string
    {
        return 'create:controller';
    }
}
