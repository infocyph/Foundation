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

    /**
     * Publish a module's Foundation integration config without replacing files
     * already owned by the host application.
     *
     * @return array{published:list<string>,existing:list<string>}
     */
    public function publishConfig(string $module): array
    {
        $definition = $this->catalog->resolve($module);
        $configDirectory = $this->application->configPath();
        $templateDirectory = dirname(__DIR__, 3) . '/resources/config';
        $published = [];
        $existing = [];

        $this->ensureDirectory($configDirectory);

        foreach ($definition['config'] as $filename) {
            $source = $templateDirectory . DIRECTORY_SEPARATOR . $filename;
            $target = $configDirectory . DIRECTORY_SEPARATOR . $filename;

            $this->publishFile($source, $target)
                ? $published[] = $target
                : $existing[] = $target;
        }

        if ($published !== []) {
            new ConfigCacheManager($this->application)->clear('bootstrap/cache/config');
        }

        return [
            'published' => $published,
            'existing' => $existing,
        ];
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

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)
            || mkdir($directory, 0775, true)
            || is_dir($directory)
        ) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Unable to create project config directory "%s".',
            $directory,
        ));
    }

    private function publishFile(string $source, string $target): bool
    {
        if (is_file($target)) {
            return false;
        }
        if (!is_file($source) || !is_readable($source)) {
            throw new \RuntimeException(sprintf(
                'Foundation config template "%s" is unavailable.',
                $source,
            ));
        }

        $contents = file_get_contents($source);
        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf(
                'Unable to read Foundation config template "%s".',
                $source,
            ));
        }

        $handle = fopen($target, 'x');
        if ($handle === false) {
            if (is_file($target)) {
                return false;
            }

            throw new \RuntimeException(sprintf(
                'Unable to create project config file "%s".',
                $target,
            ));
        }

        try {
            $this->writeFile($handle, $contents, $target);
        } catch (\Throwable $exception) {
            fclose($handle);
            unlink($target);

            throw $exception;
        }

        fclose($handle);

        return true;
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

    /**
     * @param resource $handle
     */
    private function writeFile(mixed $handle, string $contents, string $target): void
    {
        $length = strlen($contents);
        $written = 0;

        while ($written < $length) {
            $bytes = fwrite($handle, substr($contents, $written));
            if ($bytes === false || $bytes === 0) {
                throw new \RuntimeException(sprintf(
                    'Unable to write project config file "%s".',
                    $target,
                ));
            }
            $written += $bytes;
        }
    }
}
