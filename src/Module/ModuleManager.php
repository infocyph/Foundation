<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Composer\InstalledVersions;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigCacheManager;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessResult;
use Infocyph\Foundation\Process\ProcessRunner;

final readonly class ModuleManager
{
    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
        private ProcessRunner $processes,
    ) {}

    /**
     * @return list<array{
     *     name:string,
     *     description:string,
     *     built_in:bool,
     *     status:string,
     *     installed:bool,
     *     direct:bool,
     *     schemas:list<string>,
     *     packages:array<string,array{constraint:string,installed:bool,direct:bool,version:?string}>
     * }>
     */
    public function all(): array
    {
        $direct = $this->directRequirements();
        $modules = [];

        foreach ($this->catalog->all() as $name => $definition) {
            $builtIn = ($definition['built_in'] ?? false) === true;
            $packages = [];
            $installedCount = 0;
            $directCount = 0;

            foreach ($definition['packages'] as $package => $constraint) {
                $installed = InstalledVersions::isInstalled($package);
                $isDirect = isset($direct[$package]);
                $installedCount += $installed ? 1 : 0;
                $directCount += $isDirect ? 1 : 0;
                $packages[$package] = [
                    'constraint' => $constraint,
                    'installed' => $installed,
                    'direct' => $isDirect,
                    'version' => $installed ? InstalledVersions::getPrettyVersion($package) : null,
                ];
            }

            $packageCount = count($packages);
            $installed = $builtIn || ($packageCount > 0 && $installedCount === $packageCount);
            $status = match (true) {
                $builtIn => 'built-in',
                $installed => 'installed',
                $installedCount > 0 => 'partial',
                default => 'available',
            };

            $modules[] = [
                'name' => $name,
                'description' => $definition['description'],
                'built_in' => $builtIn,
                'status' => $status,
                'installed' => $installed,
                'direct' => $builtIn || ($packageCount > 0 && $directCount === $packageCount),
                'schemas' => $definition['schemas'],
                'packages' => $packages,
            ];
        }

        return $modules;
    }

    public function install(string $module, bool $dryRun = false): ProcessResult
    {
        $definition = $this->catalog->resolve($module);
        if (($definition['built_in'] ?? false) === true || $definition['packages'] === []) {
            return new ProcessResult(0);
        }

        $command = ['composer', 'require'];
        foreach ($definition['packages'] as $package => $constraint) {
            $command[] = $package . ':' . $constraint;
        }
        $command[] = '--with-all-dependencies';
        $command[] = '--update-no-dev';
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->processes->run($command, new ProcessOptions(
            cwd: $this->application->basePath(),
            interactive: true,
        ));
    }

    /** @return array{published:list<string>,existing:list<string>} */
    public function publishConfig(string $module, bool $force = false): array
    {
        $definition = $this->catalog->resolve($module);
        $configured = $definition['config'];
        if ($configured === []) {
            return ['published' => [], 'existing' => []];
        }

        $directory = $this->application->configPath();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create project config directory "%s".', $directory));
        }

        $existing = [];
        $sources = [];
        foreach ($configured as $filename) {
            if ($filename === '' || basename($filename) !== $filename) {
                continue;
            }

            $target = $directory . DIRECTORY_SEPARATOR . $filename;
            if (is_file($target) && !$force) {
                $existing[] = $target;

                continue;
            }

            $source = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'config'
                . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($source) || !is_readable($source)) {
                throw new \RuntimeException(sprintf('Config template "%s" is unavailable.', $filename));
            }
            $sources[$target] = $source;
        }

        $published = $this->publishConfigFiles($directory, $sources, $existing, $force);
        if ($published !== []) {
            new ConfigCacheManager($this->application)->clear();
        }

        return ['published' => $published, 'existing' => $existing];
    }

    public function remove(string $module, bool $dryRun = false): ProcessResult
    {
        $definition = $this->catalog->resolve($module);
        if (($definition['built_in'] ?? false) === true) {
            throw new \InvalidArgumentException(sprintf('Module "%s" is built into Foundation.', $definition['name']));
        }

        $direct = $this->directRequirements();
        $packages = array_values(array_filter(
            array_keys($definition['packages']),
            static fn(string $package): bool => isset($direct[$package]),
        ));
        if ($packages === []) {
            return new ProcessResult(0);
        }

        $command = ['composer', 'remove', ...$packages, '--with-all-dependencies', '--update-no-dev'];
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->processes->run($command, new ProcessOptions(
            cwd: $this->application->basePath(),
            interactive: true,
        ));
    }

    /** @return array<string, string> */
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
        if (!is_array($composer) || !is_array($composer['require'] ?? null)) {
            return [];
        }

        $requirements = [];
        foreach ($composer['require'] as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                $requirements[$package] = $constraint;
            }
        }

        return $requirements;
    }

    /**
     * @param array<string, string> $sources target => source
     * @param list<string> $existing
     * @return list<string>
     */
    private function publishConfigFiles(string $directory, array $sources, array &$existing, bool $force): array
    {
        if ($sources === []) {
            return [];
        }

        $staged = [];
        $backups = [];
        $published = [];

        try {
            foreach ($sources as $target => $source) {
                $temporary = tempnam($directory, '.foundation-config-');
                if ($temporary === false) {
                    throw new \RuntimeException(sprintf('Unable to stage config template "%s".', basename($target)));
                }
                $staged[$target] = $temporary;

                if (!copy($source, $temporary) || !chmod($temporary, 0664)) {
                    throw new \RuntimeException(sprintf('Unable to stage config template "%s".', basename($target)));
                }
            }

            foreach ($staged as $target => $temporary) {
                if (is_file($target)) {
                    if (!$force) {
                        $existing[] = $target;
                        unlink($temporary);
                        unset($staged[$target]);

                        continue;
                    }

                    $backup = $target . '.foundation-' . bin2hex(random_bytes(6)) . '.bak';
                    if (!rename($target, $backup)) {
                        throw new \RuntimeException(sprintf('Unable to stage existing config "%s".', basename($target)));
                    }
                    $backups[$target] = $backup;
                }

                if (!rename($temporary, $target)) {
                    throw new \RuntimeException(sprintf('Unable to publish config template "%s".', basename($target)));
                }
                unset($staged[$target]);
                $published[] = $target;
            }

            foreach ($backups as $backup) {
                if (is_file($backup)) {
                    unlink($backup);
                }
            }
        } catch (\Throwable $failure) {
            foreach ($staged as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
            foreach ($published as $target) {
                if (is_file($target)) {
                    unlink($target);
                }
            }
            foreach ($backups as $target => $backup) {
                if (is_file($backup)) {
                    rename($backup, $target);
                }
            }

            throw $failure;
        }

        return $published;
    }
}
