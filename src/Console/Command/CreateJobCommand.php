<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;

final class CreateJobCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:job', 'Create an invokable application job.', 'SendReceipt');
    }

    protected function artifact(): string
    {
        return 'job';
    }

    protected function commandName(): string
    {
        return 'create:job';
    }
}
