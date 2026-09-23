<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module\Internal;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\Internal\ConfiguredCapabilities;

/**
 * @phpstan-import-type ModuleDefinition from \Infocyph\Foundation\Module\ModuleCatalog
 * @phpstan-import-type ModuleDependency from \Infocyph\Foundation\Module\ModuleCatalog
 * @phpstan-import-type FeatureState from \Infocyph\Foundation\Module\ModuleStateResolver
 * @phpstan-import-type ModuleState from \Infocyph\Foundation\Module\ModuleStateResolver
 * @phpstan-type DependencyState array{
 *     type:'module'|'capability',
 *     target:string,
 *     reason:string,
 *     active:bool,
 *     satisfied:bool
 * }
 * @phpstan-type DependencyResolution array{
 *     active:list<DependencyState>,
 *     inactive:list<DependencyState>,
 *     satisfied:bool,
 *     blockers:list<string>,
 *     feature_satisfied:array<string,bool>
 * }
 */
final readonly class ModuleDependencyResolver
{
    public function __construct(private Application $application) {}

    /**
     * @phpstan-param ModuleDefinition $definition
     * @param array<string,FeatureState> $features
     * @param array<string,ModuleState> $modules
     * @phpstan-return DependencyResolution
     */
    public function resolve(
        array $definition,
        array $features,
        array $modules,
        ConfiguredCapabilities $capabilities,
    ): array {
        $active = [];
        $inactive = [];
        $blockers = [];
        $featureSatisfied = [];

        foreach ($definition['dependencies'] as $dependency) {
            $this->classify($dependency, $modules, $capabilities, $active, $inactive, $blockers);
        }

        foreach ($features as $feature => $state) {
            $featureSatisfied[$feature] = true;
            foreach ($state['dependencies'] as $dependency) {
                if (!$state['selected']) {
                    $inactive[] = $this->state($dependency, false, false);

                    continue;
                }

                $resolved = $this->resolveOne($dependency, $modules, $capabilities);
                $active[] = $resolved;
                if (!$resolved['satisfied']) {
                    $featureSatisfied[$feature] = false;
                    $blockers[] = sprintf(
                        'Feature %s requires %s %s: %s',
                        $feature,
                        $resolved['type'] === 'module' ? 'module' : 'core capability',
                        $resolved['target'],
                        $resolved['reason'],
                    );
                }
            }
        }

        return [
            'active' => $this->unique($active),
            'inactive' => $this->unique($inactive),
            'satisfied' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'feature_satisfied' => $featureSatisfied,
        ];
    }

    /**
     * @phpstan-param ModuleDependency $dependency
     * @param array<string,ModuleState> $modules
     * @param list<DependencyState> $active
     * @param list<DependencyState> $inactive
     * @param list<string> $blockers
     */
    private function classify(
        array $dependency,
        array $modules,
        ConfiguredCapabilities $capabilities,
        array &$active,
        array &$inactive,
        array &$blockers,
    ): void {
        if (!$this->active($dependency)) {
            $inactive[] = $this->state($dependency, false, false);

            return;
        }

        $resolved = $this->resolveOne($dependency, $modules, $capabilities);
        $active[] = $resolved;
        if (!$resolved['satisfied']) {
            $blockers[] = sprintf(
                'Required %s %s is not ready: %s',
                $resolved['type'] === 'module' ? 'module' : 'core capability',
                $resolved['target'],
                $resolved['reason'],
            );
        }
    }

    /** @phpstan-param ModuleDependency $dependency */
    private function active(array $dependency): bool
    {
        if (!isset($dependency['when'])) {
            return true;
        }

        $predicate = $dependency['when'];
        $value = $this->application->config()->get($predicate['key']);

        if ($predicate['operator'] === 'equals') {
            return $value === ($predicate['value'] ?? null);
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null && $value !== false;
    }

    /**
     * @phpstan-param ModuleDependency $dependency
     * @param array<string,ModuleState> $modules
     * @phpstan-return DependencyState
     */
    private function resolveOne(
        array $dependency,
        array $modules,
        ConfiguredCapabilities $capabilities,
    ): array {
        $satisfied = $dependency['type'] === 'module'
            ? $this->moduleSatisfied($dependency['target'], $modules)
            : $capabilities->enabled($dependency['target']);

        return $this->state($dependency, true, $satisfied);
    }

    /** @param array<string,ModuleState> $modules */
    private function moduleSatisfied(string $target, array $modules): bool
    {
        $state = $modules[$target] ?? null;
        if (!is_array($state)) {
            return false;
        }

        return ($state['installed'] ?? false) === true
            && ($state['enabled'] ?? false) === true;
    }

    /**
     * @phpstan-param ModuleDependency $dependency
     * @phpstan-return DependencyState
     */
    private function state(array $dependency, bool $active, bool $satisfied): array
    {
        return [
            'type' => $dependency['type'],
            'target' => $dependency['target'],
            'reason' => $dependency['reason'],
            'active' => $active,
            'satisfied' => $satisfied,
        ];
    }

    /**
     * @param list<DependencyState> $states
     * @return list<DependencyState>
     */
    private function unique(array $states): array
    {
        $unique = [];
        foreach ($states as $state) {
            $key = implode('|', [$state['type'], $state['target'], $state['reason'], $state['active'] ? '1' : '0']);
            $unique[$key] = $state;
        }

        return array_values($unique);
    }
}
