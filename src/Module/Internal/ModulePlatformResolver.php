<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module\Internal;

use Composer\InstalledVersions;
use Infocyph\Foundation\Application\Application;

/**
 * @phpstan-import-type ModuleDefinition from \Infocyph\Foundation\Module\ModuleCatalog
 * @phpstan-import-type FeatureState from \Infocyph\Foundation\Module\ModuleStateResolver
 * @phpstan-type PlatformResolution array{
 *     ready:bool,
 *     required_extensions:array<string,bool>,
 *     optional_extensions:array<string,bool>,
 *     packages:array<string,bool>,
 *     blockers:list<string>,
 *     feature_ready:array<string,bool>
 * }
 */
final readonly class ModulePlatformResolver
{
    public function __construct(private Application $application) {}

    /**
     * @phpstan-param ModuleDefinition $definition
     * @param array<string,FeatureState> $features
     * @phpstan-return PlatformResolution
     */
    public function resolve(string $module, array $definition, array $features, bool $enabled): array
    {
        $required = $this->extensionStates($this->requiredExtensions($module, $definition));
        $optional = $this->extensionStates($definition['platform']['optional_extensions']);
        $packages = $this->packageStates($definition['platform']['packages']);
        $blockers = [];

        if ($enabled) {
            foreach ($required as $extension => $available) {
                if (!$available) {
                    $blockers[] = sprintf('Required PHP extension ext-%s is not available.', $extension);
                }
            }
            foreach ($packages as $package => $available) {
                if (!$available) {
                    $blockers[] = sprintf('Required platform package %s is not available.', $package);
                }
            }
        }

        $featureReady = [];
        foreach ($features as $name => $state) {
            $featureRequired = $this->extensionStates($state['platform']['extensions']);
            $featurePackages = $this->packageStates($state['platform']['packages']);
            $ready = !in_array(false, $featureRequired, true)
                && !in_array(false, $featurePackages, true);
            $featureReady[$name] = $ready;

            if (!$enabled || !$state['selected'] || $ready) {
                continue;
            }
            foreach ($featureRequired as $extension => $available) {
                if (!$available) {
                    $blockers[] = sprintf('Feature %s requires PHP extension ext-%s.', $name, $extension);
                }
            }
            foreach ($featurePackages as $package => $available) {
                if (!$available) {
                    $blockers[] = sprintf('Feature %s requires platform package %s.', $name, $package);
                }
            }
        }

        return [
            'ready' => $blockers === [],
            'required_extensions' => $required,
            'optional_extensions' => $optional,
            'packages' => $packages,
            'blockers' => array_values(array_unique($blockers)),
            'feature_ready' => $featureReady,
        ];
    }

    /** @param list<string> $extensions @return array<string,bool> */
    private function extensionStates(array $extensions): array
    {
        $states = [];
        foreach (array_values(array_unique($extensions)) as $extension) {
            $states[$extension] = extension_loaded($extension);
        }
        ksort($states);

        return $states;
    }

    /** @param list<string> $packages @return array<string,bool> */
    private function packageStates(array $packages): array
    {
        $states = [];
        foreach (array_values(array_unique($packages)) as $package) {
            $states[$package] = InstalledVersions::isInstalled($package);
        }
        ksort($states);

        return $states;
    }

    /**
     * @phpstan-param ModuleDefinition $definition
     * @return list<string>
     */
    private function requiredExtensions(string $module, array $definition): array
    {
        $extensions = $definition['platform']['extensions'];
        if ($module !== 'database') {
            return $extensions;
        }

        $default = $this->application->config()->get('database.default');
        $driver = is_string($default)
            ? $this->application->config()->get('database.connections.' . $default . '.driver')
            : null;
        $driverExtension = match ($driver) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'pgsql', 'postgres', 'postgresql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'sqlsrv', 'mssql' => 'pdo_sqlsrv',
            default => null,
        };
        if ($driverExtension !== null) {
            $extensions[] = $driverExtension;
        }

        return array_values(array_unique($extensions));
    }
}
