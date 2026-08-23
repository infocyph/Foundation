<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Container\ContainerCacheManager;
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
        $requested = $this->module();
        $module = $this->catalog()->resolve($requested)['name'];
        $manager = $this->manager();
        $dryRun = $this->flag('dry-run');
        $result = $manager->install($module, $dryRun);
        if (!$result->successful()) {
            return $result->exitCode;
        }

        $published = $dryRun
            ? ['published' => [], 'existing' => []]
            : $manager->publishConfig($module);
        if (!$dryRun) {
            $this->invalidateCompiledRuntime();
        }

        if ($this->io()->machineReadable()) {
            $this->io()->json([
                'module' => $module,
                'requested' => $requested,
                'exit_code' => $result->exitCode,
                ...$published,
            ]);
        } else {
            $this->io()->success(sprintf('Module "%s" is ready.', $module));
            foreach ($published['published'] as $path) {
                $this->io()->info('Published ' . $path);
            }
        }

        return ExitCode::SUCCESS;
    }

    private function invalidateCompiledRuntime(): void
    {
        $this->application->make(ContainerCacheManager::class)->clear();
    }

    private function listing(): int
    {
        $modules = $this->manager()->all();
        if ($this->io()->machineReadable()) {
            $this->io()->json($modules);

            return ExitCode::SUCCESS;
        }

        $this->io()->table(
            ['Module', 'Status', 'Packages', 'Purpose'],
            array_map(
                fn(array $module): array => [
                    $module['name'],
                    $module['status'],
                    $this->packageSummary($module['packages']),
                    $module['description'],
                ],
                $modules,
            ),
        );

        return ExitCode::SUCCESS;
    }

    private function manager(): ModuleManager
    {
        return new ModuleManager($this->application, $this->catalog(), new ProcessRunner());
    }

    private function catalog(): ModuleCatalog
    {
        return new ModuleCatalog();
    }

    private function module(): string
    {
        return $this->argument(0) ?? throw new \LogicException('Validated module argument is unavailable.');
    }

    /** @param array<string,array{constraint:string,installed:bool,direct:bool,version:?string}> $packages */
    private function packageSummary(array $packages): string
    {
        if ($packages === []) {
            return 'Foundation';
        }

        $summary = [];
        foreach ($packages as $package => $state) {
            $summary[] = $package . ' ' . ($state['version'] ?? $state['constraint']);
        }

        return implode(', ', $summary);
    }

    private function remove(): int
    {
        $requested = $this->module();
        $module = $this->catalog()->resolve($requested)['name'];
        $dryRun = $this->flag('dry-run');
        $result = $this->manager()->remove($module, $dryRun);
        if ($result->successful() && !$dryRun) {
            $this->invalidateCompiledRuntime();
        }

        if ($this->io()->machineReadable()) {
            $this->io()->json([
                'module' => $module,
                'requested' => $requested,
                'exit_code' => $result->exitCode,
            ]);
        } elseif ($result->successful()) {
            $this->io()->success(sprintf('Module "%s" removed.', $module));
        }

        return $result->exitCode;
    }
}
