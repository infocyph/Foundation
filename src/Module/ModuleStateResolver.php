<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Composer\InstalledVersions;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\Internal\ConfiguredCapabilities;

/**
 * @phpstan-import-type ModuleDefinition from ModuleCatalog
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
    ) {}

    /** @return list<ModuleState> */
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

    /**
     * @param ModuleDefinition $definition
     * @param array{known:bool,requirements:array<string,string>,error:?string} $ownership
     * @return ModuleState
     */
    private function state(
        string $name,
        array $definition,
        array $ownership,
        ConfiguredCapabilities $capabilities,
    ): array {
        $builtIn = ($definition['built_in'] ?? false) === true;
        $packages = $this->resolvePackages($definition['packages'], $ownership);
        $packageCount = count($packages['packages']);
        $installed = $this->installedByModule($builtIn, $packageCount, $packages);
        $activationExplicit = $this->activationExplicit($name, $capabilities);
        $enabled = $this->enabled($name, $activationExplicit, $packages['all_available'], $capabilities);
        $configured = $this->configured($definition);
        $blockers = $this->moduleBlockers(
            $packages['blockers'],
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
        $ready = $this->ready($builtIn, $installed, $enabled, $configured, $blockers);
        $status = $this->status($builtIn, $installed, $enabled, $ready, $blockers);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'name' => $name,
            'description' => $definition['description'],
            'built_in' => $builtIn,
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
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string,string> $requirements
     * @param array{known:bool,requirements:array<string,string>,error:?string} $ownership
     * @return PackageResolution
     */
    private function resolvePackages(array $requirements, array $ownership): array
    {
        /** @var array<string,PackageState> $packages */
        $packages = [];
        /** @var list<string> $blockers */
        $blockers = [];
        /** @var list<string> $warnings */
        $warnings = [];

        foreach ($requirements as $package => $constraint) {
            $state = $this->packageState($package, $constraint, $ownership);
            $packages[$package] = $state;
            array_push($blockers, ...$this->packageBlockers($package, $state));
            array_push($warnings, ...$this->packageWarnings($package, $state));
        }

        if ($packages !== [] && !$ownership['known']) {
            $blockers[] = $ownership['error'] ?? 'Application Composer ownership is unknown.';
        }

        return [
            'packages' => $packages,
            'all_available' => !array_any($packages, static fn(array $state): bool => !$state['available']),
            'all_direct' => $packages === []
                || ($ownership['known'] && !array_any($packages, static fn(array $state): bool => !$state['direct'])),
            'any_transitive' => array_any($packages, static fn(array $state): bool => $state['transitive']),
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param array{known:bool,requirements:array<string,string>,error:?string} $ownership
     * @return PackageState
     */
    private function packageState(string $package, string $constraint, array $ownership): array
    {
        $available = InstalledVersions::isInstalled($package);
        $direct = $ownership['known'] && isset($ownership['requirements'][$package]);
        $directConstraint = $direct ? $ownership['requirements'][$package] : null;
        $version = $available ? InstalledVersions::getVersion($package) : null;
        $catalogCompatible = $available ? $this->satisfiesConstraint($version, $constraint) : null;
        $directConstraintCompatible = $directConstraint === null
            ? null
            : $this->constraintWithin($directConstraint, $constraint);
        $directVersionCompatible = !$available || $directConstraint === null
            ? null
            : $this->satisfiesConstraint($version, $directConstraint);

        return [
            'constraint' => $constraint,
            'installed' => $available,
            'available' => $available,
            'direct' => $direct,
            'transitive' => $ownership['known'] && $available && !$direct,
            'ownership_unknown' => !$ownership['known'],
            'direct_constraint' => $directConstraint,
            'catalog_compatible' => $catalogCompatible,
            'direct_constraint_compatible' => $directConstraintCompatible,
            'compatible' => $this->combinedCompatibility(
                $catalogCompatible,
                $directConstraintCompatible,
                $directVersionCompatible,
                $direct,
            ),
            'version' => $available ? InstalledVersions::getPrettyVersion($package) : null,
        ];
    }

    /**
     * @param PackageState $state
     * @return list<string>
     */
    private function packageBlockers(string $package, array $state): array
    {
        if (!$state['available']) {
            return [sprintf('Required package %s %s is not available.', $package, $state['constraint'])];
        }
        if ($state['catalog_compatible'] === false) {
            return [sprintf(
                'Installed package %s %s does not satisfy %s.',
                $package,
                $state['version'] ?? 'unknown',
                $state['constraint'],
            )];
        }
        if ($state['direct_constraint_compatible'] === false) {
            return [sprintf(
                'Direct Composer constraint %s for %s is outside the supported module range %s.',
                $state['direct_constraint'],
                $package,
                $state['constraint'],
            )];
        }
        if ($state['compatible'] === false) {
            return [sprintf(
                'Installed package %s %s does not satisfy the application constraint %s.',
                $package,
                $state['version'] ?? 'unknown',
                $state['direct_constraint'] ?? 'unknown',
            )];
        }

        return [];
    }

    /**
     * @param PackageState $state
     * @return list<string>
     */
    private function packageWarnings(string $package, array $state): array
    {
        if ($state['direct'] && $state['compatible'] === null) {
            return [sprintf(
                'Unable to fully evaluate direct Composer constraint %s for %s against %s.',
                $state['direct_constraint'] ?? 'unknown',
                $package,
                $state['constraint'],
            )];
        }
        if ($state['transitive']) {
            return [sprintf(
                'Package %s is available only transitively; require it directly to own this module.',
                $package,
            )];
        }

        return [];
    }

    /** @param PackageResolution $packages */
    private function installedByModule(bool $builtIn, int $packageCount, array $packages): bool
    {
        if ($builtIn) {
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

    private function activationExplicit(string $name, ConfiguredCapabilities $capabilities): bool
    {
        return in_array($name, self::TOPOLOGY_MANAGED, true)
            ? $capabilities->explicit()
            : true;
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

    /** @param list<string> $blockers */
    private function ready(
        bool $builtIn,
        bool $installed,
        bool $enabled,
        bool $configured,
        array $blockers,
    ): bool {
        return $builtIn
            ? $enabled && $configured
            : $installed && $enabled && $configured && $blockers === [];
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

    /** @param ModuleDefinition $definition */
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

    private function validConfigSection(string $key): bool
    {
        return $key !== ''
            && $this->application->config()->has($key)
            && is_array($this->application->config()->get($key));
    }

    /** @param ModuleDefinition $definition */
    private function configPublished(array $definition): bool
    {
        return !array_any(
            $definition['config'],
            fn(string $filename): bool => !is_file($this->application->configPath($filename)),
        );
    }

    private function satisfiesConstraint(?string $version, string $constraint): ?bool
    {
        if ($version === null) {
            return null;
        }

        $bounds = $this->constraintBounds($constraint);
        if ($bounds === null) {
            return null;
        }

        return version_compare($version, $bounds['lower'], '>=')
            && version_compare($version, $bounds['upper'], '<');
    }

    private function constraintWithin(string $candidate, string $required): ?bool
    {
        $candidateBounds = $this->constraintBounds($candidate);
        $requiredBounds = $this->constraintBounds($required);
        if ($candidateBounds === null || $requiredBounds === null) {
            return null;
        }

        return version_compare($candidateBounds['lower'], $requiredBounds['lower'], '>=')
            && version_compare($candidateBounds['upper'], $requiredBounds['upper'], '<=');
    }

    /** @return array{lower:string,upper:string}|null */
    private function constraintBounds(string $constraint): ?array
    {
        if (preg_match('/^\\^(\\d+)(?:\\.(\\d+))?(?:\\.(\\d+))?$/D', trim($constraint), $match) !== 1) {
            return null;
        }

        $major = (int) $match[1];
        $minor = isset($match[2]) ? (int) $match[2] : 0;
        $patch = isset($match[3]) ? (int) $match[3] : 0;
        $lower = sprintf('%d.%d.%d', $major, $minor, $patch);
        $upper = match (true) {
            $major > 0 => sprintf('%d.0.0', $major + 1),
            $minor > 0 => sprintf('0.%d.0', $minor + 1),
            default => sprintf('0.0.%d', $patch + 1),
        };

        return ['lower' => $lower, 'upper' => $upper];
    }

    private function combinedCompatibility(
        ?bool $catalog,
        ?bool $directConstraint,
        ?bool $directVersion,
        bool $direct,
    ): ?bool {
        if (in_array(false, [$catalog, $directConstraint, $directVersion], true)) {
            return false;
        }
        if ($catalog !== true) {
            return null;
        }
        if (!$direct) {
            return true;
        }

        return $directConstraint === true && $directVersion === true ? true : null;
    }

    /** @return array{known:false,requirements:array{},error:string} */
    private function unknownOwnership(string $error): array
    {
        return ['known' => false, 'requirements' => [], 'error' => $error];
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
}
