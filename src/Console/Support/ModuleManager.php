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
        private ?ModuleManifestManager $manifest = null,
    ) {}

    /**
     * @return list<array{
     *     name: string,
     *     package: string|null,
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

        foreach ($this->definitions() as $name => $definition) {
            $package = $this->package($definition);
            $builtIn = ($definition['built_in'] ?? false) === true;
            $installed = $builtIn || ($package !== null && InstalledVersions::isInstalled($package));
            $modules[] = [
                'name' => $name,
                'package' => $package,
                'description' => $this->description($definition),
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
        $definition = $this->resolve($module);
        if (($definition['built_in'] ?? false) === true) {
            return new ProcessResult(0, '', '');
        }

        $package = $this->package($definition);
        if ($package === null) {
            throw new \LogicException(sprintf('Module "%s" has no installable package.', $this->name($definition)));
        }
        $command = [
            'composer',
            'require',
            $package,
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
        $definition = $this->resolve($module);
        $configDirectory = $this->application->configPath();
        $published = [];
        $existing = [];

        $this->ensureDirectory($configDirectory);

        foreach ($this->configSources($definition) as $filename => $source) {
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
        $definition = $this->resolve($module);
        if (($definition['built_in'] ?? false) === true) {
            throw new \InvalidArgumentException(sprintf(
                'Module "%s" is built into Foundation and cannot be removed.',
                $this->name($definition),
            ));
        }

        $package = $this->package($definition);
        if ($package === null) {
            throw new \LogicException(sprintf('Module "%s" has no removable package.', $this->name($definition)));
        }
        $command = [
            'composer',
            'remove',
            $package,
            '--with-all-dependencies',
        ];
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->run($command);
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<string,string>
     */
    private function configSources(array $definition): array
    {
        $configured = $definition['config'] ?? [];
        if (!is_array($configured)) {
            return [];
        }

        $sources = [];
        $root = $definition['root'] ?? null;
        foreach ($configured as $target => $source) {
            if (is_int($target) && is_string($source)) {
                $sources[$source] = dirname(__DIR__, 3) . '/resources/config/' . $source;

                continue;
            }
            if (is_string($target) && is_string($source) && is_string($root)) {
                $sources[$target] = $root . DIRECTORY_SEPARATOR . $source;
            }
        }

        return $sources;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function definitions(): array
    {
        $thirdParty = ($this->manifest ?? new ModuleManifestManager($this->application))->load();

        return $this->catalog->all() + $thirdParty;
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function description(array $definition): string
    {
        $description = $definition['description'] ?? null;
        if (!is_string($description) || $description === '') {
            throw new \UnexpectedValueException('Foundation module description must be a non-empty string.');
        }

        return $description;
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

    /**
     * @param array<string,mixed> $definition
     */
    private function name(array $definition): string
    {
        $name = $definition['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \UnexpectedValueException('Foundation module name must be a non-empty string.');
        }

        return $name;
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function package(array $definition): ?string
    {
        $package = $definition['package'] ?? null;
        if ($package !== null && !is_string($package)) {
            throw new \UnexpectedValueException('Foundation module package must be a string or null.');
        }

        return $package;
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
     * @return array<string,mixed>
     */
    private function resolve(string $module): array
    {
        $normalized = strtolower(trim($module));
        foreach ($this->definitions() as $name => $definition) {
            $package = $definition['package'] ?? null;
            $aliases = is_array($definition['aliases'] ?? null) ? $definition['aliases'] : [];
            if ($normalized === $name
                || (is_string($package) && $normalized === $package)
                || in_array($normalized, $aliases, true)
            ) {
                return ['name' => $name] + $definition;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown module "%s". Run optimize to compile newly installed third-party modules.',
            $module,
        ));
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
