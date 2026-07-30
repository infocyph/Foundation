<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Composer\InstalledVersions;
use Infocyph\Foundation\Application\Application;

final readonly class ModuleManifestManager
{
    public function __construct(private Application $application) {}

    public function clear(string $path = 'bootstrap/cache/modules.php'): bool
    {
        $manifest = $this->path($path);

        return is_file($manifest) && (!unlink($manifest)
            ? throw new \RuntimeException(sprintf('Unable to remove module manifest "%s".', $manifest))
            : true);
    }

    /**
     * @return array<string, array{
     *   package:string,
     *   description:string,
     *   aliases:list<string>,
     *   config:array<string,string>,
     *   root:string
     * }>
     */
    public function load(string $path = 'bootstrap/cache/modules.php'): array
    {
        $manifest = $this->path($path);
        if (!is_file($manifest)) {
            return [];
        }

        $modules = require $manifest;
        if (!is_array($modules)) {
            throw new \UnexpectedValueException('Compiled Foundation module manifest must return an array.');
        }

        $normalized = [];
        foreach ($modules as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                throw new \UnexpectedValueException('Compiled Foundation modules must use string names and array definitions.');
            }
            $package = $definition['package'] ?? null;
            $description = $definition['description'] ?? null;
            $aliases = $definition['aliases'] ?? null;
            $config = $definition['config'] ?? null;
            $root = $definition['root'] ?? null;
            if (!is_string($package)
                || !is_string($description)
                || !is_array($aliases)
                || !is_array($config)
                || !is_string($root)
            ) {
                throw new \UnexpectedValueException(sprintf(
                    'Compiled Foundation module "%s" has an invalid definition.',
                    $name,
                ));
            }
            $normalized[$name] = [
                'package' => $package,
                'description' => $description,
                'aliases' => $this->stringList($aliases),
                'config' => $this->configMap($config),
                'root' => $root,
            ];
        }

        return $normalized;
    }

    public function write(string $path = 'bootstrap/cache/modules.php'): string
    {
        $manifest = $this->path($path);
        $modules = $this->discoverModules();
        ksort($modules, SORT_STRING);
        $this->writeFile($manifest, $modules);

        return $manifest;
    }

    /**
     * @return array<string,string>
     */
    private function configMap(mixed $configured): array
    {
        if (!is_array($configured)) {
            throw new \UnexpectedValueException('Third-party module config must be a target-to-source map.');
        }

        $config = [];
        foreach ($configured as $target => $source) {
            if (!is_string($target)
                || basename($target) !== $target
                || !str_ends_with($target, '.php')
                || !is_string($source)
                || $source === ''
                || str_starts_with($source, '/')
                || str_contains($source, '..')
            ) {
                throw new \UnexpectedValueException(
                    'Third-party module config entries must map PHP filenames to safe package-relative paths.',
                );
            }
            $config[$target] = $source;
        }

        return $config;
    }

    /**
     * @return array<string,string>
     */
    private function curatedClaims(): array
    {
        $claims = [];
        foreach (new ModuleCatalog()->all() as $name => $definition) {
            $claims[$name] = $name;
            foreach ($definition['aliases'] as $alias) {
                $claims[$alias] = $name;
            }
            if (is_string($definition['package'])) {
                $claims[$definition['package']] = $name;
            }
        }

        return $claims;
    }

    /**
     * @return array{
     *   package:string,
     *   description:string,
     *   aliases:list<string>,
     *   config:array<string,string>,
     *   root:string
     * }
     */
    private function definition(string $package, string $root, mixed $definition): array
    {
        if (!is_array($definition)) {
            throw new \UnexpectedValueException(sprintf(
                'Third-party module from "%s" must be an array.',
                $package,
            ));
        }

        $description = $definition['description'] ?? null;
        if (!is_string($description) || $description === '') {
            throw new \UnexpectedValueException(sprintf(
                'Third-party module from "%s" requires a description.',
                $package,
            ));
        }

        return [
            'package' => $package,
            'description' => $description,
            'aliases' => $this->stringList($definition['aliases'] ?? []),
            'config' => $this->configMap($definition['config'] ?? []),
            'root' => $root,
        ];
    }

    /**
     * @param array<string,string> $claims
     * @return array<string, array{
     *   package:string,
     *   description:string,
     *   aliases:list<string>,
     *   config:array<string,string>,
     *   root:string
     * }>
     */
    private function definitionsForPackage(string $package, array &$claims): array
    {
        $root = InstalledVersions::getInstallPath($package);
        if (!is_string($root) || $root === '') {
            return [];
        }
        $definitionFile = $root . DIRECTORY_SEPARATOR . 'foundation-module.php';
        if (!is_file($definitionFile)) {
            return [];
        }

        $definitions = require $definitionFile;
        if (!is_array($definitions)) {
            throw new \UnexpectedValueException(sprintf(
                'Third-party module file "%s" must return a module map.',
                $definitionFile,
            ));
        }

        $modules = [];
        foreach ($definitions as $name => $definition) {
            $name = $this->moduleName($name);
            $normalized = $this->definition($package, $root, $definition);
            $this->registerClaims($claims, $name, $package, $normalized['aliases']);
            $modules[$name] = $normalized;
        }

        return $modules;
    }

    /**
     * @return array<string, array{
     *   package:string,
     *   description:string,
     *   aliases:list<string>,
     *   config:array<string,string>,
     *   root:string
     * }>
     */
    private function discoverModules(): array
    {
        $modules = [];
        $claims = $this->curatedClaims();
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            $modules += $this->definitionsForPackage($package, $claims);
        }

        return $modules;
    }

    private function moduleName(mixed $name): string
    {
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new \UnexpectedValueException(
                'Third-party module names must use lowercase letters, digits, and hyphens.',
            );
        }

        return $name;
    }

    private function path(string $path): string
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? $path
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }

    /**
     * @param array<string,string> $claims
     * @param list<string> $aliases
     */
    private function registerClaims(array &$claims, string $name, string $package, array $aliases): void
    {
        $moduleClaims = array_values(array_unique([$name, ...$aliases]));
        foreach ($moduleClaims as $claim) {
            if (array_key_exists($claim, $claims)) {
                throw new \UnexpectedValueException(sprintf(
                    'Third-party module name or alias "%s" from "%s" conflicts with "%s".',
                    $claim,
                    $package,
                    $claims[$claim],
                ));
            }
        }
        foreach ($moduleClaims as $claim) {
            $claims[$claim] = $name;
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            throw new \UnexpectedValueException('Third-party module aliases must be a string list.');
        }

        $strings = [];
        foreach ($values as $value) {
            $strings[] = $this->moduleName($value);
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param array<string, array{
     *   package:string,
     *   description:string,
     *   aliases:list<string>,
     *   config:array<string,string>,
     *   root:string
     * }> $modules
     */
    private function writeFile(string $manifest, array $modules): void
    {
        $directory = dirname($manifest);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create module cache directory "%s".', $directory));
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($modules, true)
            . ";\n";
        $temporary = $manifest . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $manifest)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new \RuntimeException(sprintf('Unable to write module manifest "%s".', $manifest));
        }
    }
}
