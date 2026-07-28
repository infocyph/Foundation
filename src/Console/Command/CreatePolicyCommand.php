<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreatePolicyCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:policy', 'Create an authorization policy.', 'Invoice');
    }

    protected function artifact(): string
    {
        return 'policy';
    }

    protected function commandName(): string
    {
        return 'create:policy';
    }
}
