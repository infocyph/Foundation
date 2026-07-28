<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateEnumCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:enum', 'Create an application enum.', 'OrderStatus');
    }

    protected function artifact(): string
    {
        return 'enum';
    }

    protected function commandName(): string
    {
        return 'create:enum';
    }
}
