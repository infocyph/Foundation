<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Application\Application;

final class EnvironmentShowCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('env:show')
            ->description('Display the active application environment and configuration-cache state.')
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        $report = [
            'environment' => $this->application->environment() ?? 'unknown',
            'debug' => (bool) $this->application->config()->get('app.debug', false),
            'configuration_cached' => $this->application->config()->isCompiled(),
            'configuration_cache' => $this->application->config()->cacheDirectory(),
        ];

        if ($this->options()->bool('json')) {
            $this->report($report);
        } else {
            $this->io()->details($report);
        }

        return ExitCode::SUCCESS;
    }
}
