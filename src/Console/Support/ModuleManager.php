<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Composer\InstalledVersions;
use Infocyph\Console\Process\ProcessMode;
use Infocyph\Console\Process\ProcessOptions;
use Infocyph\Console\Process\ProcessResult;
use Infocyph\Console\Process\ProcessRunner;
use Infocyph\Foundation\Application\Application;

final readonly class ModuleManager
{
    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
        private ProcessRunner $processes,
    ) {}

    /**
     * @return list<array{
     *     name: string,
     *     package: string,
     *     description: string,
     *     installed: bool,
     *     direct: bool,
     *     version: string|null
     * }>
     */
    public function all(): array
    {
        $direct = $this->directRequirements();
        $modules = [];

        foreach ($this->catalog->all() as $name => $definition) {
            $package = $definition['package'];
            $installed = InstalledVersions::isInstalled($package);
            $modules[] = [
                'name' => $name,
                'package' => $package,
                'description' => $definition['description'],
                'installed' => $installed,
                'direct' => isset($direct[$package]),
                'version' => $installed ? InstalledVersions::getPrettyVersion($package) : null,
            ];
        }

        return $modules;
    }

    public function install(string $module, bool $dryRun = false): ProcessResult
    {
        $definition = $this->catalog->resolve($module);
        $command = [
            'composer',
            'require',
            $definition['package'],
            '--with-all-dependencies',
        ];
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->run($command);
    }

    public function remove(string $module, bool $dryRun = false): ProcessResult
    {
        $definition = $this->catalog->resolve($module);
        $command = [
            'composer',
            'remove',
            $definition['package'],
            '--with-all-dependencies',
        ];
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->run($command);
    }

    /**
     * @return array<string, mixed>
     */
    private function directRequirements(): array
    {
        $path = $this->application->basePath('composer.json');
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            return [];
        }

        try {
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($composer)) {
            return [];
        }

        $requirements = $composer['require'] ?? [];
        if (!is_array($requirements)) {
            return [];
        }

        $direct = [];
        foreach ($requirements as $package => $constraint) {
            if (is_string($package)) {
                $direct[$package] = $constraint;
            }
        }

        return $direct;
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): ProcessResult
    {
        return $this->processes->run($command, new ProcessOptions(
            workingDirectory: $this->application->basePath(),
            inheritInput: true,
            mode: ProcessMode::INHERIT,
        ));
    }
}
