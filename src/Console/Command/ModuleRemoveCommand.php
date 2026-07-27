<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Argument;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Console\Support\ModuleManager;

final class ModuleRemoveCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ModuleManager $modules) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('module:remove')
            ->description('Remove an optional direct Infocyph module dependency.')
            ->argument(Argument::required('module')->description(
                'Module name or package, for example: db, cache, filesystem, or infocyph/otp.',
            ))
            ->option(Option::flag('dry-run')->description(
                'Resolve and display Composer changes without writing them.',
            ));
    }

    protected function handle(): int
    {
        try {
            $result = $this->modules->remove(
                $this->arguments()->string('module'),
                $this->options()->bool('dry-run'),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->io()->error($exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        return $result->successful() ? ExitCode::SUCCESS : $result->exitCode;
    }
}
