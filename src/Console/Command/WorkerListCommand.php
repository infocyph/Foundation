<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\WorkerManager;

final class WorkerListCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly WorkerManager $workers) {}

    public static function define(CommandDefinition $command): void
    {
        $command->name('worker:list')->description('List application worker providers.');
    }

    protected function handle(): int
    {
        $rows = [];
        foreach ($this->workers->all() as $name => $provider) {
            $rows[] = [$name, $provider];
        }
        $this->io()->table(['Worker', 'Provider'], $rows);

        return ExitCode::SUCCESS;
    }
}
