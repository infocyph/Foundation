<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\ModuleManager;

final class ModuleListCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly ModuleManager $modules) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('module:list')
            ->description('List optional Infocyph modules and installation state.')
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        $modules = $this->modules->all();
        if ($this->options()->bool('json')) {
            $this->report(['modules' => $modules]);

            return ExitCode::SUCCESS;
        }

        $rows = [];
        foreach ($modules as $module) {
            $rows[] = [
                $module['name'],
                $module['installed'] ? ($module['direct'] ? 'direct' : 'transitive') : 'not installed',
                $module['version'] ?? '-',
                $module['package'] ?? 'built-in',
            ];
        }
        $this->io()->table(['Module', 'Status', 'Version', 'Package'], $rows);

        return ExitCode::SUCCESS;
    }
}
