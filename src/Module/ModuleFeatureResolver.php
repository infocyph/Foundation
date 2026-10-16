<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

/**
 * Pure, fixture-friendly resolution of the selected 4.0 feature topology.
 * No Composer, environment, application filesystem or installed-version reads.
 *
 * @phpstan-import-type ArchitectureFeature from ModuleArchitectureCatalog
 * @phpstan-type Context array{
 *     root_known:bool,
 *     root_requirements:array<string,string>,
 *     installed:array<string,string>,
 *     composer_fingerprint?:string,
 *     configuration_fingerprint?:string,
 *     activation_fingerprint?:string,
 *     enabled:list<string>,
 *     selected_features?:list<string>,
 *     config:array<string,mixed>,
 *     extensions:list<string>,
 *     allowlist?:list<string>,
 *     overrides?:array<string,bool>,
 *     published_configs?:list<string>
 * }
 * @phpstan-type PackageState array{
 *     constraint:string,version:?string,direct:bool,transitive:bool,
 *     root_constraint:?string,compatible:?bool,root_compatible:?bool
 * }
 * @phpstan-type FeatureState array{
 *     owner:string,enabled:bool,allowed:bool,packages:array<string,PackageState>,
 *     dependencies:list<string>,missing_extensions:list<string>,
 *     config:list<string>,schemas:list<string>,blockers:list<string>
 * }
 * @phpstan-type Resolution array{
 *     schema_version:2,selected:list<string>,root_known:bool,
 *     states:array<string,FeatureState>,blockers:list<string>
 * }
 */
final readonly class ModuleFeatureResolver
{
    private ModuleArchitectureCatalog $catalog;

    public function __construct(?ModuleArchitectureCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? new ModuleArchitectureCatalog();
    }

    /**
     * @param list<string> $features
     *
     * @return list<string>
     */
    public function normalize(array $features): array
    {
        $requested = [];

        foreach ($features as $id) {
            $this->catalog->resolve($id);
            $requested[$id] = true;
        }

        $normalized = [];

        foreach ($this->catalog->all() as $module => $definition) {
            foreach (array_keys($definition['features']) as $feature) {
                $id = $module . '.' . $feature;

                if (isset($requested[$id])) {
                    $normalized[] = $id;
                }
            }
        }

        return $normalized;
    }

    /**
     * @phpstan-param Context $context
     * @param list<string> $features
     * @phpstan-return Resolution
     */
    public function resolve(array $features, array $context): array
    {
        $selected = $this->normalize($features);
        $states = [];
        $blockers = [];

        foreach ($selected as $id) {
            $definition = $this->catalog->resolve($id)['definition'];
            $state = $this->featureState($id, $definition, $context);
            $states[$id] = $state;
            array_push($blockers, ...$state['blockers']);
        }

        return [
            'schema_version' => 2,
            'selected' => $selected,
            'root_known' => $context['root_known'],
            'states' => $states,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private static function comparableVersion(string $version): ?string
    {
        $version = ltrim($version, 'v');
        if (preg_match('/^\\d+(?:\\.\\d+){0,3}$/D', $version) !== 1) {
            return null;
        }

        $parts = explode('.', $version);
        if (count($parts) === 4 && $parts[3] !== '0') {
            return null;
        }

        return implode('.', array_slice(array_pad($parts, 3, '0'), 0, 3));
    }

    /**
     * @param list<array{target:string,when?:array{key:string,operator:'equals'|'not-empty',value?:bool|int|string}}> $dependencies
     * @param array<string,mixed> $config
     * @return list<string>
     */
    private function activeDependencies(array $dependencies, array $config): array
    {
        $active = [];

        foreach ($dependencies as $dependency) {
            if (!isset($dependency['when']) || $this->condition($dependency['when'], $config)) {
                $active[] = $dependency['target'];
            }
        }

        return $active;
    }

    /** @phpstan-param Context $context */
    private function allowed(string $id, array $context): bool
    {
        if (isset($context['allowlist']) && !in_array($id, $context['allowlist'], true)) {
            return false;
        }

        return ($context['overrides'][$id] ?? true) !== false;
    }

    /**
     * @param array{key:string,operator:'equals'|'not-empty',value?:bool|int|string} $when
     * @param array<string,mixed> $config
     */
    private function condition(array $when, array $config): bool
    {
        $value = $config;

        foreach (explode('.', $when['key']) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }

            $value = $value[$key];
        }

        if ($when['operator'] === 'equals') {
            return $value === ($when['value'] ?? null);
        }

        return is_string($value) ? trim($value) !== '' : $value !== null && $value !== false && $value !== [];
    }

    /** @return array{string,string}|null */
    private function constraintBounds(string $constraint): ?array
    {
        if (preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/D', $constraint, $match) !== 1) {
            return null;
        }

        $major = (int) $match[1];

        return [
            sprintf('%d.%d.%d', $major, (int) ($match[2] ?? 0), (int) ($match[3] ?? 0)),
            sprintf('%d.0.0', $major + 1),
        ];
    }

    /**
     * Conservative fixed major-floor comparison; unsupported Composer syntax
     * returns null and must be resolved by a real Composer solver at apply.
     */
    private function constraintMatches(string $actual, string $required): ?bool
    {
        if (preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/D', $required, $floor) !== 1) {
            return null;
        }

        $major = (int) $floor[1];
        $lower = sprintf('%d.%d.%d', $major, (int) ($floor[2] ?? 0), (int) ($floor[3] ?? 0));
        $upper = sprintf('%d.0.0', $major + 1);

        if (str_starts_with($actual, '^')) {
            $nested = $this->constraintBounds($actual);

            return $nested !== null
                && version_compare($nested[0], $lower, '>=')
                && version_compare($nested[1], $upper, '<=');
        }

        $version = self::comparableVersion($actual);

        return $version === null
            ? null
            : version_compare($version, $lower, '>=')
                && version_compare($version, $upper, '<');
    }

    /**
     * @phpstan-param Context $context
     * @phpstan-return FeatureState
     * @phpstan-param ArchitectureFeature $definition
     */
    private function featureState(string $id, array $definition, array $context): array
    {
        $packages = [];
        $blockers = [];

        foreach ($definition['packages'] as $package => $constraint) {
            $state = $this->packageState($package, $constraint, $context);
            $packages[$package] = $state;

            if ($state['compatible'] === false) {
                $blockers[] = sprintf('Installed %s is outside supported range %s.', $package, $constraint);
            }

            if ($state['compatible'] === null && $state['version'] !== null) {
                $blockers[] = sprintf('Cannot verify installed version of %s against %s.', $package, $constraint);
            }

            if ($state['root_compatible'] === false) {
                $blockers[] = sprintf('Root Composer requirement for %s is outside %s.', $package, $constraint);
            }

            if ($state['root_compatible'] === null && $state['direct']) {
                $blockers[] = sprintf('Cannot verify root Composer constraint for %s.', $package);
            }
        }

        $missingExtensions = array_values(array_diff($definition['platform']['extensions'], $context['extensions']));
        $allowed = $this->allowed($id, $context);

        return [
            'owner' => $definition['owner'],
            'enabled' => in_array($id, $context['enabled'], true),
            'allowed' => $allowed,
            'packages' => $packages,
            'dependencies' => $this->activeDependencies($definition['dependencies'], $context['config']),
            'missing_extensions' => $missingExtensions,
            'config' => $definition['config'],
            'schemas' => $definition['schemas'],
            'blockers' => $blockers,
        ];
    }

    /**
     * @phpstan-param Context $context
     * @phpstan-return PackageState
     */
    private function packageState(string $package, string $constraint, array $context): array
    {
        $version = $context['installed'][$package] ?? null;
        $root = $context['root_known'] ? ($context['root_requirements'][$package] ?? null) : null;
        $direct = $root !== null;

        return [
            'constraint' => $constraint,
            'version' => $version,
            'direct' => $direct,
            'transitive' => $context['root_known'] && !$direct && $version !== null,
            'root_constraint' => $root,
            'compatible' => $version === null ? null : $this->constraintMatches($version, $constraint),
            'root_compatible' => $root === null ? null : $this->constraintMatches($root, $constraint),
        ];
    }
}
