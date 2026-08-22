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

    /** @return list<array{name:string,package:?string,constraint:?string,description:string,installed:bool,direct:bool,version:?string}> */
    public function all(): array
    {
        $direct = $this->directRequirements();
        $modules = [];

        foreach ($this->catalog->all() as $name => $definition) {
            $package = $definition['package'];
            $builtIn = ($definition['built_in'] ?? false) === true;
            $installed = $builtIn || ($package !== null && InstalledVersions::isInstalled($package));
            $modules[] = [
                'name' => $name,
                'package' => $package,
                'constraint' => $definition['constraint'],
                'description' => $definition['description'],
                'installed' => $installed,
                'direct' => $builtIn || ($package !== null && isset($direct[$package])),
                'version' => $builtIn
                    ? InstalledVersions::getPrettyVersion('infocyph/foundation')
                    : ($installed && $package !== null ? InstalledVersions::getPrettyVersion($package) : null),
            ];
        }

        return $modules;
    }

    public function install(string $module, bool $dryRun = false): ProcessResult
    {
        $definition = $this->catalog->resolve($module);
        if (($definition['built_in'] ?? false) === true) {
            return new ProcessResult(0);
        }

        $package = $definition['package'] ?? null;
        $constraint = $definition['constraint'] ?? null;
        if (!is_string($package) || $package === '' || !is_string($constraint) || $constraint === '') {
            throw new \LogicException(sprintf('Module "%s" has no installable package constraint.', $module));
        }

        $requirement = $package . ':' . $constraint;
        $command = ['composer', 'require', $requirement, '--with-all-dependencies', '--update-no-dev'];
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->processes->run($command, new ProcessOptions(
            cwd: $this->application->basePath(),
            interactive: true,
        ));
    }

    /** @return array{published:list<string>,existing:list<string>} */
    public function publishConfig(string $module): array
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
            if (is_file($target)) {
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

        $published = $this->publishConfigFiles($directory, $sources, $existing);
        if ($published !== []) {
            new ConfigCacheManager($this->application)->clear();
        }

        return ['published' => $published, 'existing' => $existing];
    }

    public function remove(string $module, bool $dryRun = false): ProcessResult
    {
        $definition = $this->catalog->resolve($module);
        if (($definition['built_in'] ?? false) === true) {
            throw new \InvalidArgumentException(sprintf('Module "%s" is built into Foundation.', $module));
        }

        $package = $definition['package'] ?? null;
        if (!is_string($package) || $package === '') {
            throw new \LogicException(sprintf('Module "%s" has no removable package.', $module));
        }

        $command = ['composer', 'remove', $package, '--with-all-dependencies', '--update-no-dev'];
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
    private function publishConfigFiles(string $directory, array $sources, array &$existing): array
    {
        if ($sources === []) {
            return [];
        }

        $staged = [];
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
                if (@link($temporary, $target)) {
                    unlink($temporary);
                    unset($staged[$target]);
                    $published[] = $target;

                    continue;
                }
                if (is_file($target)) {
                    $existing[] = $target;
                    unlink($temporary);
                    unset($staged[$target]);

                    continue;
                }

                throw new \RuntimeException(sprintf('Unable to publish config template "%s".', basename($target)));
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

            throw $failure;
        }

        return $published;
    }
}
