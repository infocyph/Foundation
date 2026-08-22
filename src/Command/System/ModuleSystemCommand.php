<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Module\ModuleManager;
use Infocyph\Foundation\Process\ProcessRunner;

final class ModuleSystemCommand extends SystemCommand
{
    public function __construct(private readonly Application $application) {}

    protected function handle(): int
    {
        return match ($this->canonicalName()) {
            'module:install' => $this->install(),
            'module:list' => $this->listing(),
            'module:remove' => $this->remove(),
            default => throw new \LogicException('Unsupported module system command.'),
        };
    }

    private function install(): int
    {
        $module = $this->module();
        $manager = $this->manager();
        $result = $manager->install($module, $this->flag('dry-run'));
        if (!$result->successful()) {
            return $result->exitCode;
        }

        $published = $this->flag('dry-run')
            ? ['published' => [], 'existing' => []]
            : $manager->publishConfig($module);
        if ($this->io()->machineReadable()) {
            $this->io()->json([
                'module' => $module,
                'exit_code' => $result->exitCode,
                ...$published,
            ]);
        } else {
            $this->io()->success(sprintf('Module "%s" installed.', $module));
            foreach ($published['published'] as $path) {
                $this->io()->info('Published ' . $path);
            }
        }

        return ExitCode::SUCCESS;
    }

    private function listing(): int
    {
        $modules = $this->manager()->all();
        if ($this->io()->machineReadable()) {
            $this->io()->json($modules);

            return ExitCode::SUCCESS;
        }

        $this->io()->table(
            ['Module', 'Installed', 'Direct', 'Version', 'Package'],
            array_map(
                static fn(array $module): array => [
                    $module['name'],
                    $module['installed'],
                    $module['direct'],
                    $module['version'] ?? '',
                    $module['package'] ?? 'built-in',
                ],
                $modules,
            ),
        );

        return ExitCode::SUCCESS;
    }

    private function manager(): ModuleManager
    {
        return new ModuleManager($this->application, new ModuleCatalog(), new ProcessRunner());
    }

    private function module(): string
    {
        return $this->argument(0) ?? throw new \LogicException('Validated module argument is unavailable.');
    }

    private function remove(): int
    {
        $module = $this->module();
        $result = $this->manager()->remove($module, $this->flag('dry-run'));
        if ($this->io()->machineReadable()) {
            $this->io()->json(['module' => $module, 'exit_code' => $result->exitCode]);
        } elseif ($result->successful()) {
            $this->io()->success(sprintf('Module "%s" removed.', $module));
        }

        return $result->exitCode;
    }
}
