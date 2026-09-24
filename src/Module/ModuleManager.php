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
        $command[] = '--no-interaction';
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->processes->run($command, new ProcessOptions(
            cwd: $this->application->basePath(),
            interactive: false,
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

        $resolver = new ModuleStateResolver($this->application, $this->catalog);
        $ownership = $resolver->rootRequirements();
        if (!$ownership['known']) {
            throw new \RuntimeException(
                'Unable to determine direct Composer ownership: '
                . ($ownership['error'] ?? 'application composer.json is unavailable.'),
            );
        }

        $states = $resolver->all();
        $state = array_find(
            $states,
            static fn(array $candidate): bool => $candidate['name'] === $definition['name'],
        );
        if (!is_array($state)) {
            throw new \RuntimeException(sprintf('Unable to resolve module "%s".', $definition['name']));
        }
        $this->assertRemovalSafe($definition['name'], $definition['requested_features'], $state, $states);

        $packages = $this->removalPackages(
            $definition,
            $definition['requested_features'],
            $ownership['requirements'],
        );
        if ($packages === []) {
            return new ProcessResult(0);
        }

        $command = ['composer', 'remove', ...$packages, '--with-all-dependencies', '--no-interaction'];
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->processes->run($command, new ProcessOptions(
            cwd: $this->application->basePath(),
            interactive: false,
        ));
    }

    /**
     * @param list<string> $features
     * @phpstan-param ModuleState $state
     * @phpstan-param list<ModuleState> $states
     */
    private function assertRemovalSafe(string $module, array $features, array $state, array $states): void
    {
        $blockers = $features === [] && $state['enabled']
            ? [sprintf('Module "%s" is enabled; disable it before removing packages.', $module)]
            : [];

        array_push($blockers, ...$this->selectedFeatureRemovalBlockers($module, $features, $state));
        if ($features === []) {
            array_push($blockers, ...$this->dependentRemovalBlockers($module, $states));
        }

        if ($blockers === []) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Module "%s" cannot be removed: %s',
            $module,
            implode('; ', array_values(array_unique($blockers))),
        ));
    }

    /**
     * @phpstan-param list<ModuleState> $states
     * @return list<string>
     */
    private function dependentRemovalBlockers(string $module, array $states): array
    {
        $blockers = [];
        foreach ($states as $candidate) {
            if (!$candidate['enabled'] || $candidate['name'] === $module) {
                continue;
            }

            foreach ($candidate['dependencies']['active'] as $dependency) {
                if ($dependency['type'] === 'module' && $dependency['target'] === $module) {
                    $blockers[] = sprintf('%s: %s', $candidate['name'], $dependency['reason']);
                }
            }
        }

        return $blockers;
    }

    /**
     * @phpstan-param ModuleDefinition $definition
     * @param list<string> $otherFeatures
     * @param array<string,string> $direct
     */
    private function otherFeatureOwnsPackage(
        array $definition,
        string $package,
        array $otherFeatures,
        array $direct,
    ): bool {
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

    /**
     * @param list<string> $features
     * @phpstan-param ModuleState $state
     * @return list<string>
     */
    private function selectedFeatureRemovalBlockers(string $module, array $features, array $state): array
    {
        $blockers = [];
        foreach ($features as $feature) {
            if ($state['enabled'] && $state['features'][$feature]['selected']) {
                $blockers[] = sprintf(
                    'Feature "%s" is selected on enabled module "%s"; change configuration before removal.',
                    $feature,
                    $module,
                );
            }
        }

        return $blockers;
    }
}
