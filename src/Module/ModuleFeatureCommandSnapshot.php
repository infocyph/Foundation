<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;

/**
 * CLI-only fresh Composer/project snapshot for the v2 feature planner.
 * Never reads Composer manifests from the compiled runtime or request path.
 *
 * @phpstan-import-type Context from ModuleFeatureResolver
 */
final readonly class ModuleFeatureCommandSnapshot
{
    public function __construct(private Application $application) {}

    /** @phpstan-return Context */
    public function capture(): array
    {
        $config = $this->application->config();
        $flags = ModuleFeatureCapabilityPolicy::fromConfig($config) ?? [];
        $enabled = [];
        foreach ($flags as $name => $active) {
            if ($active) {
                $enabled[] = $name;
            }
        }
        $root = $this->rootRequirements();

        return [
            'root_known' => $root['known'],
            'root_requirements' => $root['requirements'],
            'installed' => $this->installedVersions(),
            'enabled' => $enabled,
            // Explicitly configured, even disabled, specialist owners protect
            // shared packages until their selection is intentionally removed.
            'selected_features' => array_keys($flags),
            'config' => $config->all(),
            'extensions' => array_values(array_unique(array_map(strtolower(...), get_loaded_extensions()))),
            'published_configs' => $this->publishedConfigs(),
        ];
    }

    /** @return array<string,string> */
    private function installedVersions(): array
    {
        $path = $this->application->basePath('vendor/composer/installed.json');
        if (is_link($path) || !is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $packages = is_array($decoded) ? ($decoded['packages'] ?? $decoded) : null;
        if (!is_array($packages)) {
            return [];
        }

        $versions = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $name = $package['name'] ?? null;
            $version = $package['version_normalized'] ?? $package['version'] ?? $package['pretty_version'] ?? null;
            if (is_string($name) && $name !== '' && is_string($version) && $version !== '') {
                $versions[$name] = $version;
            }
        }
        ksort($versions);

        return $versions;
    }

    /** @return list<string> */
    private function publishedConfigs(): array
    {
        $published = [];
        foreach (new ModuleArchitectureCatalog()->all() as $module) {
            foreach ($module['features'] as $feature) {
                foreach ($feature['config'] as $filename) {
                    if (is_file($this->application->configPath($filename))) {
                        $published[$filename] = true;
                    }
                }
            }
        }
        $files = array_keys($published);
        sort($files);

        return $files;
    }

    /** @return array{known:bool,requirements:array<string,string>} */
    private function rootRequirements(): array
    {
        $path = $this->application->basePath('composer.json');
        if (is_link($path) || !is_file($path)) {
            return ['known' => false, 'requirements' => []];
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return ['known' => false, 'requirements' => []];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['known' => false, 'requirements' => []];
        }
        if (!is_array($decoded) || !is_array($decoded['require'] ?? [])) {
            return ['known' => false, 'requirements' => []];
        }

        $requirements = [];
        foreach ($decoded['require'] ?? [] as $name => $constraint) {
            if (!is_string($name) || !is_string($constraint)) {
                return ['known' => false, 'requirements' => []];
            }
            $requirements[$name] = $constraint;
        }

        return ['known' => true, 'requirements' => $requirements];
    }
}
