<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateMiddlewareCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:middleware', 'Create HTTP route middleware.', 'EnsureTenant');
    }

    protected function artifact(): string
    {
        return 'middleware';
    }

    protected function commandName(): string
    {
        return 'create:middleware';
    }
}
