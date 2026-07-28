<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateTestCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:test', 'Create a Pest feature test.', 'Http/UserAccess');
    }

    protected function artifact(): string
    {
        return 'test';
    }

    protected function commandName(): string
    {
        return 'create:test';
    }
}
