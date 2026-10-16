<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;

/**
 * CLI-only fresh Composer/project snapshot for the v2 feature planner.
 * Never reads Composer manifests from the compiled runtime or request path.
 *
 * @phpstan-import-type Context from ModuleFeatureResolver
 */
final readonly class ModuleFeatureCommandSnapshot
{
    public function __construct(private Application $application) {}

    /** @phpstan-param Context $context */
    public function assertCurrent(array $context, bool $includingActivation = true): void
    {
        if (isset($context['composer_fingerprint']) && $context['composer_fingerprint'] !== $this->composerFingerprint()) {
            throw new \RuntimeException('Application Composer metadata changed; re-plan before applying.');
        }
        if (!isset($context['configuration_fingerprint'], $context['activation_fingerprint'])) {
            return;
        }
        $current = $this->configurationFingerprint();
        if ($context['configuration_fingerprint'] !== $current['sources']
            || ($includingActivation && $context['activation_fingerprint'] !== $current['activation'])
        ) {
            throw new \RuntimeException('Feature configuration changed; re-plan before applying.');
        }
    }

    /** @phpstan-return Context */
    public function capture(): array
    {
        $configuration = $this->configurationFingerprint();
        $config = $this->sourceConfiguration();
        $flags = ModuleFeatureCapabilityPolicy::fromConfig($config) ?? [];
        $enabled = [];
        foreach ($flags as $name => $active) {
            if ($active) {
                $enabled[] = $name;
            }
        }
        $metadata = $this->composerMetadata();
        $fingerprint = hash('xxh3', serialize($metadata));
        $root = $this->rootRequirements($metadata['composer.json']);
        $installed = $this->installedVersions($metadata['vendor/composer/installed.json']);
        if ($fingerprint !== $this->composerFingerprint()) {
            throw new \RuntimeException('Composer metadata changed while capturing the feature snapshot; retry.');
        }

        if ($configuration !== $this->configurationFingerprint()) {
            throw new \RuntimeException('Application configuration changed while capturing the feature snapshot; retry.');
        }

        return [
            'configuration_fingerprint' => $configuration['sources'],
            'activation_fingerprint' => $configuration['activation'],
            'root_known' => $root['known'],
            'root_requirements' => $root['requirements'],
            'installed' => $installed,
            'composer_fingerprint' => $fingerprint,
            'enabled' => $enabled,
            // Explicitly configured, even disabled, specialist owners protect
            // shared packages until their selection is intentionally removed.
            'selected_features' => array_keys($flags),
            'config' => $config->all(),
            'extensions' => array_values(array_unique(array_map(strtolower(...), get_loaded_extensions()))),
            'published_configs' => $this->publishedConfigs(),
        ];
    }

    /**
     * Change detector, not a security signature. Both the CLI and the locked
     * activation path read the same authoritative Composer root/lock/install data.
     */
    private function composerFingerprint(): string
    {
        return hash('xxh3', serialize($this->composerMetadata()));
    }

    /** @return array<string,?string> */
    private function composerMetadata(): array
    {
        $parts = [];
        foreach (['composer.json', 'composer.lock', 'vendor/composer/installed.json'] as $file) {
            $path = $this->application->basePath($file);
            if (is_link($path)) {
                throw new \RuntimeException('Composer metadata must not be symlinked during module activation.');
            }

            $contents = is_file($path) ? file_get_contents($path) : null;
            if ($contents === false) {
                throw new \RuntimeException('Unable to read Composer metadata during module activation.');
            }

            $parts[$file] = $contents;
        }

        return $parts;
    }

    /** @return array{sources:string,activation:string} */
    private function configurationFingerprint(): array
    {
        $directory = $this->application->configPath();
        if (is_link($directory)) {
            throw new \RuntimeException('Module configuration directory must not be symlinked.');
        }
        $sources = [];
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            if (is_link($file)) {
                throw new \RuntimeException('Module configuration sources must not be symlinked.');
            }
            $bytes = file_get_contents($file);
            if ($bytes === false) {
                throw new \RuntimeException('Unable to read module configuration source.');
            }
            $sources[basename($file)] = $bytes;
        }
        $activation = $sources['modules.php'] ?? null;
        unset($sources['modules.php']);
        ksort($sources);

        return [
            'sources' => hash('xxh3', serialize($sources)),
            'activation' => hash('xxh3', serialize($activation)),
        ];
    }

    /** @return array<string,string> */
    private function installedVersions(?string $contents): array
    {
        if ($contents === null) {
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
    private function rootRequirements(?string $contents): array
    {
        if ($contents === null) {
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

    private function sourceConfiguration(): ConfigRepository
    {
        $existing = $this->application->config();
        $fresh = new ConfigLoader()->load([
            'app' => ['base_path' => $this->application->basePath(), 'load_env' => false],
            'paths' => ['config' => $this->application->configPath()],
            '_config_cache' => false,
        ]);
        $values = $existing->all();
        foreach (array_unique([...$existing->lazyNamespaces(), ...$fresh->lazyNamespaces()]) as $namespace) {
            $values[$namespace] = $fresh->get($namespace);
        }

        return new ConfigRepository($values);
    }
}
