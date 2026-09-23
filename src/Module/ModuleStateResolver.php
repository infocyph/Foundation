<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Composer\InstalledVersions;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\Internal\ConfiguredCapabilities;
use Infocyph\Foundation\Module\Internal\ModulePackageStateResolver;

/**
 * @phpstan-import-type ModuleDefinition from ModuleCatalog
 * @phpstan-import-type ModuleDependency from ModuleCatalog
 * @phpstan-import-type ModuleFeature from ModuleCatalog
 * @phpstan-import-type PlatformRequirement from ModuleCatalog
 * @phpstan-type OptionalPackageState array{
 *     available:bool,
 *     version:?string,
 *     features:list<string>
 * }
 * @phpstan-type FeatureState array{
 *     description:string,
 *     aliases:list<string>,
 *     selected:bool,
 *     installed:bool,
 *     available:bool,
 *     direct:bool,
 *     ready:bool,
 *     packages:array<string,PackageState>,
 *     blockers:list<string>,
 *     dependencies:list<ModuleDependency>,
 *     platform:PlatformRequirement
 * }
 * @phpstan-type PackageState array{
 *     constraint:string,
 *     installed:bool,
 *     available:bool,
 *     direct:bool,
 *     transitive:bool,
 *     ownership_unknown:bool,
 *     direct_constraint:?string,
 *     catalog_compatible:?bool,
 *     direct_constraint_compatible:?bool,
 *     compatible:?bool,
 *     version:?string
 * }
 * @phpstan-type PackageResolution array{
 *     packages:array<string,PackageState>,
 *     all_available:bool,
 *     all_direct:bool,
 *     any_transitive:bool,
 *     blockers:list<string>,
 *     warnings:list<string>
 * }
 * @phpstan-type ModuleState array{
 *     schema_version:int,
 *     name:string,
 *     description:string,
 *     built_in:bool,
 *     core_backed:bool,
 *     status:string,
 *     installed:bool,
 *     installed_by_module:bool,
 *     package_available:bool,
 *     direct:bool,
 *     transitive:bool,
 *     ownership_unknown:bool,
 *     enabled:bool,
 *     activation_explicit:bool,
 *     configured:bool,
 *     config_published:bool,
 *     dependencies_satisfied:bool,
 *     platform_ready:bool,
 *     schema_ready:?bool,
 *     ready:bool,
 *     schemas:list<string>,
 *     packages:array<string,PackageState>,
 *     optional_integrations:array<string,OptionalPackageState>,
 *     features:array<string,FeatureState>,
 *     dependency_declarations:list<ModuleDependency>,
 *     platform:PlatformRequirement,
 *     blockers:list<string>,
 *     warnings:list<string>
 * }
 */
final readonly class ModuleStateResolver
{
    private const int SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const array TOPOLOGY_MANAGED = [
        'auth',
        'communication',
        'database',
        'filesystem',
        'messaging',
        'security',
        'session',
        'validation',
    ];

    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
        private ModulePackageStateResolver $packageStates = new ModulePackageStateResolver(),
    ) {}

    /**
     * @phpstan-return list<ModuleState>
     */
    public function all(): array
    {
        $ownership = $this->rootRequirements();
        $capabilities = new ConfiguredCapabilities($this->application->config());
        $modules = [];

        foreach ($this->catalog->all() as $name => $definition) {
            $modules[] = $this->state($name, $definition, $ownership, $capabilities);
        }

        return $modules;
    }

    /** @return array{known:bool,requirements:array<string,string>,error:?string} */
    public function rootRequirements(): array
    {
        $path = $this->application->basePath('composer.json');
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            return $this->unknownOwnership('Application composer.json is missing or unreadable.');
        }

        try {
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            return $this->unknownOwnership('Application composer.json is invalid: ' . $failure->getMessage());
        }

        if (!is_array($composer)) {
            return $this->unknownOwnership('Application composer.json must decode to an object.');
        }

        $require = $composer['require'] ?? [];
        if (!is_array($require)) {
            return $this->unknownOwnership('Application composer.json require must be an object.');
        }

        return [
            'known' => true,
            'requirements' => $this->stringRequirements($require),
            'error' => null,
        ];
    }

    private function activationExplicit(string $name, ConfiguredCapabilities $capabilities): bool
    {
        return in_array($name, self::TOPOLOGY_MANAGED, true)
            ? $capabilities->explicit()
            : true;
    }

    /**
     * @phpstan-param ModuleDefinition $definition
     */
    private function configPublished(array $definition): bool
    {
        return !array_any(
            $definition['config'],
            fn(string $filename): bool => !is_file($this->application->configPath($filename)),
        );
    }

    /**
     * @phpstan-param ModuleDefinition $definition
     */
    private function configured(array $definition): bool
    {
        foreach ($definition['config'] as $filename) {
            $key = pathinfo($filename, PATHINFO_FILENAME);
            if (!$this->validConfigSection($key)) {
                return false;
            }
        }

        return true;
    }

    private function enabled(
        string $name,
        bool $activationExplicit,
        bool $packagesAvailable,
        ConfiguredCapabilities $capabilities,
    ): bool {
        if (!in_array($name, self::TOPOLOGY_MANAGED, true)) {
            return true;
        }
        if ($activationExplicit) {
            return $capabilities->enabled($name);
        }
        if (in_array($name, ['auth', 'session'], true)) {
            return true;
        }

        return $packagesAvailable;
    }

    /**
     * @phpstan-param ModuleDefinition $definition
     * @param array{known:bool,requirements:array<string,string>,error:?string} $ownership
     * @return array<string,FeatureState>
     */
    private function featureStates(array $definition, array $ownership): array
    {
        $states = [];

        foreach ($definition['features'] as $name => $feature) {
            $packages = $this->packageStates->resolve(
                $this->catalog->featurePackages($definition, $name),
                $ownership,
            );
            $packageCount = count($packages['packages']);
            $selected = isset($feature['when']) && $this->predicateActive($feature['when']);
            $installed = $packageCount > 0
                && $packages['all_available']
                && $packages['all_direct']
                && !array_any(
                    $packages['packages'],
                    static fn(array $package): bool => $package['compatible'] === false,
                );
            $blockers = $selected && !$installed
                ? $packages['blockers']
                : [];

            $states[$name] = [
                'description' => $feature['description'],
                'aliases' => $feature['aliases'],
                'selected' => $selected,
                'installed' => $installed,
                'available' => $packages['all_available'],
                'direct' => $packages['all_direct'],
                'ready' => $selected && $installed && $blockers === [],
                'packages' => $packages['packages'],
                'blockers' => $blockers,
                'dependencies' => $feature['dependencies'],
                'platform' => $feature['platform'],
            ];
        }

        return $states;
    }

    /**
     * @phpstan-param PackageResolution $packages
     */
    private function installedByModule(
        bool $builtIn,
        bool $coreBacked,
        int $packageCount,
        array $packages,
    ): bool {
        if ($builtIn || $coreBacked) {
            return true;
        }
        if ($packageCount === 0 || !$packages['all_available'] || !$packages['all_direct']) {
            return false;
        }

        return !array_any(
            $packages['packages'],
            static fn(array $package): bool => $package['compatible'] === false,
        );
    }

    /**
     * @param list<string> $blockers
     * @return list<string>
     */
    private function moduleBlockers(
        array $blockers,
        bool $enabled,
        bool $transitive,
        bool $installed,
        bool $configured,
    ): array {
        if ($enabled && $transitive && !$installed) {
            $blockers[] = 'Enabled capability relies on transitive package ownership.';
        }
        if (!$configured) {
            $blockers[] = 'Resolved Foundation configuration is incomplete for this module.';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    private function moduleWarnings(
        array $warnings,
        string $name,
        bool $enabled,
        bool $activationExplicit,
    ): array {
        if ($enabled && !$activationExplicit && in_array($name, self::TOPOLOGY_MANAGED, true)) {
            $warnings[] = sprintf(
                'Capability %s is active through compatibility auto-discovery; app.capabilities is not explicit.',
                $name,
            );
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @phpstan-param ModuleDefinition $definition
     * @return array<string,OptionalPackageState>
     */
    private function optionalIntegrations(array $definition): array
    {
        $integrations = [];

        foreach ($definition['packages'] as $package => $requirement) {
            if ($requirement['role'] !== 'optional') {
                continue;
            }

            $available = InstalledVersions::isInstalled($package);
            $integrations[$package] = [
                'available' => $available,
                'version' => $available ? InstalledVersions::getPrettyVersion($package) : null,
                'features' => $requirement['features'],
            ];
        }

        return $integrations;
    }

    /** @param array{key:string,operator:'equals'|'not-empty',value?:bool|int|string|null} $predicate */
    private function predicateActive(array $predicate): bool
    {
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

    /** @param list<string> $blockers */
    private function ready(
        bool $builtIn,
        bool $coreBacked,
        bool $installed,
        bool $enabled,
        bool $configured,
        array $blockers,
    ): bool {
        return $builtIn || $coreBacked
            ? $enabled && $configured && $blockers === []
            : $installed && $enabled && $configured && $blockers === [];
    }

    /**
     * @phpstan-param ModuleDefinition $definition
     * @param array{known:bool,requirements:array<string,string>,error:?string} $ownership
     * @phpstan-return ModuleState
     */
    private function state(
        string $name,
        array $definition,
        array $ownership,
        ConfiguredCapabilities $capabilities,
    ): array {
        $builtIn = ($definition['built_in'] ?? false) === true;
        $coreBacked = ($definition['core_backed'] ?? false) === true;
        $packages = $this->packageStates->resolve($this->catalog->requiredPackages($definition), $ownership);
        $packageCount = count($packages['packages']);
        $installed = $this->installedByModule($builtIn, $coreBacked, $packageCount, $packages);
        $activationExplicit = $this->activationExplicit($name, $capabilities);
        $enabled = $this->enabled($name, $activationExplicit, $packages['all_available'], $capabilities);
        $configured = $this->configured($definition);
        $features = $this->featureStates($definition, $ownership);
        $featureBlockers = [];
        foreach ($features as $feature => $state) {
            if ($state['selected'] && !$state['ready']) {
                foreach ($state['blockers'] as $blocker) {
                    $featureBlockers[] = sprintf('Feature %s: %s', $feature, $blocker);
                }
            }
        }
        $blockers = $this->moduleBlockers(
            [...$packages['blockers'], ...$featureBlockers],
            $enabled,
            $packages['any_transitive'],
            $installed,
            $configured,
        );
        $warnings = $this->moduleWarnings(
            $packages['warnings'],
            $name,
            $enabled,
            $activationExplicit,
        );
        $ready = $this->ready($builtIn, $coreBacked, $installed, $enabled, $configured, $blockers);
        $status = $this->status($builtIn, $installed, $enabled, $ready, $blockers);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'name' => $name,
            'description' => $definition['description'],
            'built_in' => $builtIn,
            'core_backed' => $coreBacked,
            'status' => $status,
            'installed' => $installed,
            'installed_by_module' => $installed,
            'package_available' => $packages['all_available'],
            'direct' => $packages['all_direct'],
            'transitive' => $packages['any_transitive'],
            'ownership_unknown' => !$ownership['known'] && $packageCount > 0,
            'enabled' => $enabled,
            'activation_explicit' => $activationExplicit,
            'configured' => $configured,
            'config_published' => $this->configPublished($definition),
            'dependencies_satisfied' => true,
            'platform_ready' => true,
            'schema_ready' => $definition['schemas'] === [] ? true : null,
            'ready' => $ready,
            'schemas' => $definition['schemas'],
            'packages' => $packages['packages'],
            'optional_integrations' => $this->optionalIntegrations($definition),
            'features' => $features,
            'dependency_declarations' => $definition['dependencies'],
            'platform' => $definition['platform'],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /** @param list<string> $blockers */
    private function status(
        bool $builtIn,
        bool $installed,
        bool $enabled,
        bool $ready,
        array $blockers,
    ): string {
        return match (true) {
            $builtIn => 'built-in',
            $enabled && $blockers !== [] => 'blocked',
            $ready => 'ready',
            $installed && $enabled => 'enabled',
            $installed => 'installed',
            default => 'available',
        };
    }

    /**
     * @param array<mixed,mixed> $requirements
     * @return array<string,string>
     */
    private function stringRequirements(array $requirements): array
    {
        $normalized = [];
        foreach ($requirements as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                $normalized[$package] = $constraint;
            }
        }

        return $normalized;
    }

    /** @return array{known:false,requirements:array{},error:string} */
    private function unknownOwnership(string $error): array
    {
        return ['known' => false, 'requirements' => [], 'error' => $error];
    }

    private function validConfigSection(string $key): bool
    {
        return $key !== ''
            && $this->application->config()->has($key)
            && is_array($this->application->config()->get($key));
    }
}
