<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Application\Application;

final class AppReadyCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('app:ready')
            ->description('Validate Foundation production readiness.')
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        $report = $this->application->boot()->readinessReport();
        $this->report($report);

        return $report['production_ready'] === true
            ? ExitCode::SUCCESS
            : ExitCode::INVALID_USAGE;
    }
}
