<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Argument;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\ModuleManager;

final class ModuleInstallCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ModuleManager $modules) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('module:install')
            ->description('Install an optional Infocyph module as a direct project dependency.')
            ->argument(Argument::required('module')->description(
                'Module name or package, for example: db, cache, filesystem, or infocyph/otp.',
            ))
            ->option(Option::flag('dry-run')->description(
                'Resolve and display Composer changes without writing them.',
            ));
    }

    protected function handle(): int
    {
        $module = $this->arguments()->string('module');
        $dryRun = $this->options()->bool('dry-run');

        try {
            $result = $this->modules->install(
                $module,
                $dryRun,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->io()->error($exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        if (!$result->successful() || $dryRun) {
            return $result->successful() ? ExitCode::SUCCESS : $result->exitCode;
        }

        try {
            $config = $this->modules->publishConfig($module);
        } catch (\Throwable $exception) {
            $this->io()->error('Module installed, but config publication failed: ' . $exception->getMessage());

            return ExitCode::FAILURE;
        }

        foreach ($config['published'] as $path) {
            $this->io()->success('Published module config: ' . $path);
        }
        foreach ($config['existing'] as $path) {
            $this->io()->info('Kept existing module config: ' . $path);
        }

        return ExitCode::SUCCESS;
    }
}
