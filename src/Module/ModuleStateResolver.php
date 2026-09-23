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

    /**
     * @return array{known:bool,requirements:array<string,string>,error:?string}
     */
    public function rootRequirements(): array
    {
        $path = $this->application->basePath('composer.json');
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            return [
                'known' => false,
                'requirements' => [],
                'error' => 'Application composer.json is missing or unreadable.',
            ];
        }

        try {
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            return [
                'known' => false,
                'requirements' => [],
                'error' => 'Application composer.json is invalid: ' . $failure->getMessage(),
            ];
        }

        if (!is_array($composer)) {
            return [
                'known' => false,
                'requirements' => [],
                'error' => 'Application composer.json must decode to an object.',
            ];
        }

        $require = $composer['require'] ?? [];
        if (!is_array($require)) {
            return [
                'known' => false,
                'requirements' => [],
                'error' => 'Application composer.json require must be an object.',
            ];
        }

        $requirements = [];
        foreach ($require as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                $requirements[$package] = $constraint;
            }
        }

        return ['known' => true, 'requirements' => $requirements, 'error' => null];
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
        $packages = [];
        $allAvailable = true;
        $allDirect = true;
        $anyAvailable = false;
        $anyTransitive = false;
        $blockers = [];
        $warnings = [];

        foreach ($definition['packages'] as $package => $constraint) {
            $available = InstalledVersions::isInstalled($package);
            $direct = $ownership['known'] && isset($ownership['requirements'][$package]);
            $transitive = $ownership['known'] && $available && !$direct;
            $version = $available ? InstalledVersions::getVersion($package) : null;
            $prettyVersion = $available ? InstalledVersions::getPrettyVersion($package) : null;
            $directConstraint = $direct ? $ownership['requirements'][$package] : null;
            $catalogCompatible = $available ? $this->satisfiesConstraint($version, $constraint) : null;
            $directConstraintCompatible = $directConstraint !== null
                ? $this->constraintWithin($directConstraint, $constraint)
                : null;
            $directVersionCompatible = $available && $directConstraint !== null
                ? $this->satisfiesConstraint($version, $directConstraint)
                : null;
            $compatible = $this->combinedCompatibility(
                $catalogCompatible,
                $directConstraintCompatible,
                $directVersionCompatible,
                $direct,
            );

            $allAvailable = $allAvailable && $available;
            $allDirect = $allDirect && $direct;
            $anyAvailable = $anyAvailable || $available;
            $anyTransitive = $anyTransitive || $transitive;

            if (!$available) {
                $blockers[] = sprintf('Required package %s %s is not available.', $package, $constraint);
            } elseif ($catalogCompatible === false) {
                $blockers[] = sprintf(
                    'Installed package %s %s does not satisfy %s.',
                    $package,
                    $prettyVersion ?? $version ?? 'unknown',
                    $constraint,
                );
            } elseif ($directConstraintCompatible === false) {
                $blockers[] = sprintf(
                    'Direct Composer constraint %s for %s is outside the supported module range %s.',
                    $directConstraint,
                    $package,
                    $constraint,
                );
            } elseif ($directVersionCompatible === false) {
                $blockers[] = sprintf(
                    'Installed package %s %s does not satisfy the application constraint %s.',
                    $package,
                    $prettyVersion ?? $version ?? 'unknown',
                    $directConstraint,
                );
            } elseif ($direct && $compatible === null) {
                $warnings[] = sprintf(
                    'Unable to fully evaluate direct Composer constraint %s for %s against %s.',
                    $directConstraint,
                    $package,
                    $constraint,
                );
            } elseif ($transitive) {
                $warnings[] = sprintf(
                    'Package %s is available only transitively; require it directly to own this module.',
                    $package,
                );
            }

            $packages[$package] = [
                'constraint' => $constraint,
                'installed' => $available,
                'available' => $available,
                'direct' => $direct,
                'transitive' => $transitive,
                'ownership_unknown' => !$ownership['known'],
                'direct_constraint' => $directConstraint,
                'catalog_compatible' => $catalogCompatible,
                'direct_constraint_compatible' => $directConstraintCompatible,
                'compatible' => $compatible,
                'version' => $prettyVersion,
            ];
        }

        $packageCount = count($packages);
        if ($packageCount === 0) {
            $allAvailable = true;
            $allDirect = true;
        } elseif (!$ownership['known']) {
            $allDirect = false;
            $blockers[] = $ownership['error'] ?? 'Application Composer ownership is unknown.';
        }

        $topologyManaged = in_array($name, self::TOPOLOGY_MANAGED, true);
        $enabled = $topologyManaged ? $capabilities->enabled($name) : true;
        $activationExplicit = $topologyManaged ? $capabilities->explicit() : true;
        $configured = $this->configured($definition);
        $configPublished = $this->configPublished($definition);
        $installedByModule = $builtIn || ($packageCount > 0 && $allAvailable && $allDirect
            && !array_any($packages, static fn(array $package): bool => $package['compatible'] === false));

        if ($topologyManaged && !$activationExplicit) {
            $warnings[] = sprintf(
                'Capability %s is active through compatibility auto-discovery; app.capabilities is not explicit.',
                $name,
            );
        }

        if (!$configured) {
            $blockers[] = 'Resolved Foundation configuration is incomplete for this module.';
        }

        $ready = $builtIn
            ? $enabled && $configured
            : $installedByModule && $enabled && $configured && $blockers === [];

        $status = match (true) {
            $builtIn => 'built-in',
            $blockers !== [] => 'blocked',
            $ready => 'ready',
            $installedByModule && $enabled => 'enabled',
            $installedByModule => 'installed',
            default => 'available',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'name' => $name,
            'description' => $definition['description'],
            'built_in' => $builtIn,
            'status' => $status,
            'installed' => $installedByModule,
            'installed_by_module' => $installedByModule,
            'package_available' => $allAvailable,
            'direct' => $allDirect,
            'transitive' => $anyTransitive,
            'ownership_unknown' => !$ownership['known'] && $packageCount > 0,
            'enabled' => $enabled,
            'activation_explicit' => $activationExplicit,
            'configured' => $configured,
            'config_published' => $configPublished,
            'dependencies_satisfied' => true,
            'platform_ready' => true,
            'schema_ready' => $definition['schemas'] === [] ? true : null,
            'ready' => $ready,
            'schemas' => $definition['schemas'],
            'packages' => $packages,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings))
        ];
    }

    /** @param ModuleDefinition $definition */
    private function configured(array $definition): bool
    {
        foreach ($definition['config'] as $filename) {
            $key = pathinfo($filename, PATHINFO_FILENAME);
            if ($key === ''
                || !$this->application->config()->has($key)
                || !is_array($this->application->config()->get($key))
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param ModuleDefinition $definition */
    private function configPublished(array $definition): bool
    {
        foreach ($definition['config'] as $filename) {
            if (!is_file($this->application->configPath($filename))) {
                return false;
            }
        }

        return true;
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

        if ($major > 0) {
            $upper = sprintf('%d.0.0', $major + 1);
        } elseif ($minor > 0) {
            $upper = sprintf('0.%d.0', $minor + 1);
        } else {
            $upper = sprintf('0.0.%d', $patch + 1);
        }

        return ['lower' => $lower, 'upper' => $upper];
    }

    private function combinedCompatibility(
        ?bool $catalog,
        ?bool $directConstraint,
        ?bool $directVersion,
        bool $direct,
    ): ?bool {
        if ($catalog === false || $directConstraint === false || $directVersion === false) {
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
}
