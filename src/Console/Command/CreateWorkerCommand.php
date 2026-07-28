<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateWorkerCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:worker', 'Create a supervised worker provider.', 'Queue');
    }

    protected function artifact(): string
    {
        return 'worker';
    }

    protected function commandName(): string
    {
        return 'create:worker';
    }
}
