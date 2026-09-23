<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigCacheManager;
use Infocyph\Foundation\Module\Internal\ModuleConfigPublisher;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessResult;
use Infocyph\Foundation\Process\ProcessRunner;

/**
 * @phpstan-import-type ModuleDefinition from ModuleCatalog
 * @phpstan-import-type ModuleState from ModuleStateResolver
 */
final readonly class ModuleManager
{
    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
        private ProcessRunner $processes,
    ) {}

    /** @phpstan-return list<ModuleState> */
    public function all(): array
    {
        return new ModuleStateResolver($this->application, $this->catalog)->all();
    }

    /** @param list<string> $features */
    public function install(string $module, array $features = [], bool $dryRun = false): ProcessResult
    {
        $definition = $this->catalog->resolve($module, $features);
        $packages = $this->catalog->installationPackages($definition, $definition['requested_features']);
        if (($definition['built_in'] ?? false) === true || $packages === []) {
            return new ProcessResult(0);
        }

        $command = ['composer', 'require'];
        foreach ($packages as $package => $constraint) {
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
        $configured = $this->catalog->resolve($module)['config'];
        $result = new ModuleConfigPublisher($this->application)->publish($configured, $force);
        if ($result['published'] !== []) {
            new ConfigCacheManager($this->application)->clear();
        }

        return $result;
    }

    /** @param list<string> $features */
    public function remove(string $module, array $features = [], bool $dryRun = false): ProcessResult
    {
        $definition = $this->catalog->resolve($module, $features);
        if (($definition['built_in'] ?? false) === true) {
            throw new \InvalidArgumentException(sprintf('Module "%s" is built into Foundation.', $definition['name']));
        }

        $ownership = new ModuleStateResolver($this->application, $this->catalog)->rootRequirements();
        if (!$ownership['known']) {
            throw new \RuntimeException(
                'Unable to determine direct Composer ownership: '
                . ($ownership['error'] ?? 'application composer.json is unavailable.'),
            );
        }

        $packages = $this->removalPackages(
            $definition,
            $definition['requested_features'],
            $ownership['requirements'],
        );
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

    /**
     * @param array<string,mixed> $definition
     * @phpstan-param ModuleDefinition $definition
     * @param list<string> $otherFeatures
     * @param array<string,string> $direct
     */
    private function otherFeatureOwnsPackage(
        array $definition,
        string $package,
        array $otherFeatures,
        array $direct,
    ): bool
    {
        foreach ($otherFeatures as $feature) {
            $packages = $this->catalog->featurePackages($definition, $feature);
            unset($packages[$package]);

            if ($packages === []) {
                return true;
            }
            if (array_any(
                array_keys($packages),
                static fn(string $candidate): bool => isset($direct[$candidate]),
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $definition
     * @phpstan-param ModuleDefinition $definition
     * @param list<string> $features
     * @param array<string,string> $direct
     * @return list<string>
     */
    private function removalPackages(array $definition, array $features, array $direct): array
    {
        $managed = $this->catalog->managedPackages($definition);
        if ($features !== []) {
            $managed = [];
            foreach ($features as $feature) {
                $managed = array_replace($managed, $this->catalog->featurePackages($definition, $feature));
            }
        }

        $packages = [];
        foreach (array_keys($managed) as $package) {
            if (!isset($direct[$package])) {
                continue;
            }

            if ($features !== []) {
                $owners = $definition['packages'][$package]['features'] ?? [];
                $otherFeatures = array_values(array_diff($owners, $features));
                if ($otherFeatures !== []
                    && $this->otherFeatureOwnsPackage($definition, $package, $otherFeatures, $direct)
                ) {
                    continue;
                }
            }

            $packages[] = $package;
        }

        return $packages;
    }
}
