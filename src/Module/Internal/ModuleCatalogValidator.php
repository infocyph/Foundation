<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module\Internal;

/**
 * @phpstan-import-type ModuleDefinition from \Infocyph\Foundation\Module\ModuleCatalog
 * @phpstan-import-type ModuleDependency from \Infocyph\Foundation\Module\ModuleCatalog
 * @phpstan-import-type ModuleFeature from \Infocyph\Foundation\Module\ModuleCatalog
 * @phpstan-import-type PackageRequirement from \Infocyph\Foundation\Module\ModuleCatalog
 * @phpstan-import-type PlatformRequirement from \Infocyph\Foundation\Module\ModuleCatalog
 */
final class ModuleCatalogValidator
{
    /** @phpstan-param array<string,ModuleDefinition> $modules */
    public function validate(array $modules): void
    {
        $this->assertAliases($modules);

        foreach ($modules as $name => $definition) {
            $this->assertFeatures($name, $definition['features']);
            $this->assertPackages($name, $definition['packages'], $definition['features']);
            $this->assertDependencies($name, $definition['dependencies'], $modules);
            $this->assertPlatform($name, $definition['platform'], $definition['packages']);

            foreach ($definition['features'] as $feature => $featureDefinition) {
                $scope = $name . ':' . $feature;
                $this->assertDependencies($scope, $featureDefinition['dependencies'], $modules, $name);
                $this->assertPlatform($scope, $featureDefinition['platform'], $definition['packages']);
            }
        }

        $graph = $this->dependencyGraph($modules);
        $visiting = [];
        $visited = [];
        foreach (array_keys($graph) as $module) {
            $this->visit($module, $graph, $visiting, $visited);
        }
    }

    /** @phpstan-param array<string,ModuleDefinition> $modules */
    private function assertAliases(array $modules): void
    {
        $owners = [];

        foreach ($modules as $name => $definition) {
            $this->registerAlias($owners, $name, $name);
            foreach ($definition['aliases'] as $alias) {
                $this->registerAlias($owners, $alias, $name);
            }
            foreach ($definition['features'] as $feature) {
                foreach ($feature['aliases'] as $alias) {
                    $this->registerAlias($owners, $alias, $name);
                }
            }
            foreach ($definition['packages'] as $package => $requirement) {
                if ($requirement['role'] !== 'optional') {
                    $this->registerAlias($owners, $package, $name);
                }
            }
        }
    }

    /**
     * @phpstan-param list<ModuleDependency> $dependencies
     * @phpstan-param array<string,ModuleDefinition> $modules
     */
    private function assertDependencies(
        string $scope,
        array $dependencies,
        array $modules,
        ?string $owner = null,
    ): void
    {
        $owner ??= $scope;

        foreach ($dependencies as $dependency) {
            if ($dependency['reason'] === '') {
                throw new \LogicException(sprintf('Module dependency "%s" must explain why it is required.', $scope));
            }

            if ($dependency['type'] === 'module') {
                if (!isset($modules[$dependency['target']])) {
                    throw new \LogicException(sprintf(
                        'Module dependency "%s" targets unknown module "%s".',
                        $scope,
                        $dependency['target'],
                    ));
                }
                if ($dependency['target'] === $owner) {
                    throw new \LogicException(sprintf('Module "%s" cannot depend on itself.', $owner));
                }
            } elseif ($dependency['target'] === '') {
                throw new \LogicException(sprintf('Capability dependency "%s" has an empty target.', $scope));
            }

            $when = $dependency['when'] ?? null;
            if ($when !== null) {
                $this->assertPredicate($scope, $when);
            }
        }
    }

    /** @phpstan-param array<string,ModuleFeature> $features */
    private function assertFeatures(string $module, array $features): void
    {
        foreach ($features as $name => $feature) {
            if ($name === '' || $feature['description'] === '') {
                throw new \LogicException(sprintf('Module "%s" contains an incomplete feature definition.', $module));
            }

            $when = $feature['when'] ?? null;
            if ($when !== null) {
                $this->assertPredicate($module . ':' . $name, $when);
            }
        }
    }

    /**
     * @param list<string> $owners
     * @phpstan-param array<string,ModuleFeature> $features
     */
    private function assertPackageFeatureOwners(
        string $module,
        string $package,
        array $owners,
        array $features,
    ): void {
        foreach ($owners as $feature) {
            if (!isset($features[$feature])) {
                throw new \LogicException(sprintf(
                    'Package "%s" references unknown feature "%s" on module "%s".',
                    $package,
                    $feature,
                    $module,
                ));
            }
        }
    }

    /**
     * @phpstan-param PackageRequirement $requirement
     * @phpstan-param array<string,ModuleFeature> $features
     */
    private function assertPackageRequirement(
        string $module,
        string $package,
        array $requirement,
        array $features,
    ): void {
        if ($package === '') {
            throw new \LogicException(sprintf('Module "%s" contains an empty package name.', $module));
        }

        $role = $requirement['role'];
        if ($role !== 'optional' && $requirement['constraint'] === null) {
            throw new \LogicException(sprintf('Managed package "%s" requires a version constraint.', $package));
        }
        if ($role === 'feature' && $requirement['features'] === []) {
            throw new \LogicException(sprintf('Feature package "%s" has no feature owner.', $package));
        }
        if ($role === 'required' && $requirement['features'] !== []) {
            throw new \LogicException(sprintf('Required package "%s" cannot declare feature owners.', $package));
        }

        $this->assertPackageFeatureOwners($module, $package, $requirement['features'], $features);
    }

    /**
     * @phpstan-param array<string,PackageRequirement> $packages
     * @phpstan-param array<string,ModuleFeature> $features
     */
    private function assertPackages(string $module, array $packages, array $features): void
    {
        foreach ($packages as $package => $requirement) {
            $this->assertPackageRequirement($module, $package, $requirement, $features);
        }
    }

    /**
     * @phpstan-param PlatformRequirement $platform
     * @phpstan-param array<string,PackageRequirement> $packages
     */
    private function assertPlatform(string $scope, array $platform, array $packages): void
    {
        foreach (['extensions', 'optional_extensions'] as $key) {
            foreach ($platform[$key] as $extension) {
                if ($extension === '') {
                    throw new \LogicException(sprintf('Platform requirement "%s" contains an empty extension.', $scope));
                }
            }
        }

        foreach ($platform['packages'] as $package) {
            if (!isset($packages[$package])) {
                throw new \LogicException(sprintf(
                    'Platform requirement "%s" references undeclared package "%s".',
                    $scope,
                    $package,
                ));
            }
        }
    }

    /** @param array{key:string,operator:'equals'|'not-empty',value?:bool|int|string|null} $predicate */
    private function assertPredicate(string $scope, array $predicate): void
    {
        if ($predicate['key'] === '') {
            throw new \LogicException(sprintf('Module metadata "%s" has an invalid config predicate.', $scope));
        }
        if ($predicate['operator'] === 'equals' && !array_key_exists('value', $predicate)) {
            throw new \LogicException(sprintf('Module metadata "%s" equality predicate needs a value.', $scope));
        }
    }

    /**
     * @phpstan-param array<string,ModuleDefinition> $modules
     * @return array<string,list<string>>
     */
    private function dependencyGraph(array $modules): array
    {
        $graph = array_fill_keys(array_keys($modules), []);

        foreach ($modules as $name => $definition) {
            $dependencies = $definition['dependencies'];
            foreach ($definition['features'] as $feature) {
                array_push($dependencies, ...$feature['dependencies']);
            }

            foreach ($dependencies as $dependency) {
                if ($dependency['type'] === 'module') {
                    $graph[$name][] = $dependency['target'];
                }
            }

            $graph[$name] = array_values(array_unique($graph[$name]));
        }

        return $graph;
    }

    /** @param array<string,string> $owners */
    private function registerAlias(array &$owners, string $alias, string $module): void
    {
        $normalized = strtolower(trim($alias));
        if ($normalized === '') {
            throw new \LogicException(sprintf('Module "%s" contains an empty alias.', $module));
        }

        $owner = $owners[$normalized] ?? null;
        if ($owner !== null && $owner !== $module) {
            throw new \LogicException(sprintf(
                'Module identifier "%s" is shared by "%s" and "%s".',
                $normalized,
                $owner,
                $module,
            ));
        }

        $owners[$normalized] = $module;
    }

    /**
     * @param array<string,list<string>> $graph
     * @param array<string,bool> $visiting
     * @param array<string,bool> $visited
     */
    private function visit(string $module, array $graph, array &$visiting, array &$visited): void
    {
        if (isset($visited[$module])) {
            return;
        }
        if (isset($visiting[$module])) {
            throw new \LogicException(sprintf('Module dependency graph contains a cycle at "%s".', $module));
        }

        $visiting[$module] = true;
        foreach ($graph[$module] ?? [] as $dependency) {
            $this->visit($dependency, $graph, $visiting, $visited);
        }

        unset($visiting[$module]);
        $visited[$module] = true;
    }
}
