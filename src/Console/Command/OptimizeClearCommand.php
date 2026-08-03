<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;

final class OptimizeClearCommand extends AbstractOptimizeCommand
{
    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('optimize:clear')
            ->description('Remove configuration, route, and command caches.');
    }

    protected function handle(): int
    {
        try {
            $this->config->clear('bootstrap/cache/config');
            $this->routes->clearAll();
            $this->commands->clear('bootstrap/cache/console/commands.php');
            $this->schedule->clear();
            $this->modules->clear();
        } catch (\Throwable $exception) {
            $this->io()->error('optimize:clear failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->success('Application caches cleared.');

        return ExitCode::SUCCESS;
    }
}
