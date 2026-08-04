<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\EnvironmentSecretManager;

final class ApplicationInstallCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly EnvironmentSecretManager $environment) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('app:install')
            ->description('Initialize the application environment and authentication secret.');
    }

    protected function handle(): int
    {
        try {
            $path = $this->environment->install();
        } catch (\Throwable $exception) {
            $this->io()->error('app:install failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->io()->success('Application environment is ready at: ' . $path);

        return ExitCode::SUCCESS;
    }
}
