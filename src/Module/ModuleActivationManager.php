<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Closure;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigCacheManager;

/**
 * @phpstan-import-type Context from ModuleFeatureResolver
 */
final readonly class ModuleActivationManager
{
    public function __construct(
        private Application $application,
    ) {}

    /**
     * Persist selected optional feature overrides without changing the legacy
     * module capability map. The production graph adopts these in Batch 4/6.
     *
     * @param list<string> $features
     * @phpstan-param Context $context
     */
    public function applyFeatures(string $operation, array $features, array $context): string
    {
        if (!in_array($operation, ['enable', 'disable'], true)) {
            throw new \InvalidArgumentException('Feature activation requires enable or disable.');
        }

        $plan = new ModuleFeaturePlanner()->plan($operation, $features, $context);
        if (!$plan['applicable']) {
            throw new \RuntimeException('Feature activation is blocked: ' . implode('; ', $plan['blockers']));
        }

        $lockPath = $this->application->basePath('.foundation-module-composer.lock');
        if (is_link($lockPath)) {
            throw new \RuntimeException('Refusing symbolic-linked module Composer lock.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open module Composer lock.');
        }

        try {
            if (!flock($lock, LOCK_SH)) {
                throw new \RuntimeException('Unable to acquire shared module Composer lock.');
            }

            return $this->mutate(
                fn(array $config): array => $this->updateFeatureFlags(
                    $config,
                    $plan['effects']['activate'],
                    $context,
                    $operation,
                    $features,
                ),
                $context,
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @phpstan-param Context $context
     * @param array<string,bool> $flags
     * @param list<string> $features
     */
    private function assertFeatureGraph(array $flags, array $context, string $operation, array $features): void
    {
        $nextContext = $context;
        $nextContext['enabled'] = array_keys(array_filter($flags));
        $nextContext['selected_features'] = array_keys($flags);
        $modules = $nextContext['config']['modules'] ?? [];
        $nextContext['config']['modules'] = is_array($modules)
            ? ['features' => $flags] + $modules
            : ['features' => $flags];

        $plan = new ModuleFeaturePlanner()->plan($operation, $features, $context);
        if (!$plan['applicable']) {
            throw new \RuntimeException('Feature activation changed; re-plan before applying.');
        }
        $resolved = new ModuleFeatureResolver()->resolve($nextContext['enabled'], $nextContext);
        if ($resolved['blockers'] !== []) {
            throw new \RuntimeException('Feature activation creates invalid dependencies: ' . implode('; ', $resolved['blockers']));
        }
        foreach ($resolved['states'] as $state) {
            foreach ($state['dependencies'] as $dependency) {
                if (!in_array($dependency, $nextContext['enabled'], true)) {
                    throw new \RuntimeException('Feature activation creates invalid dependency graph.');
                }
            }
        }
    }

    /**
     * @phpstan-param Context $context
     * @param array<string,mixed> $config
     * @return array<string,bool>
     */
    private function assertFreshFeatureFlags(array $config, array $context): array
    {
        $flags = $config['features'] ?? [];
        if (!is_array($flags)) {
            throw new \RuntimeException('modules.features must be a feature map.');
        }
        foreach ($flags as $id => $active) {
            if (!is_string($id) || !is_bool($active)) {
                throw new \RuntimeException('modules.features must contain boolean feature flags.');
            }
        }

        $expected = $context['selected_features'] ?? array_keys($flags);
        $actual = array_keys($flags);
        sort($expected);
        sort($actual);
        $snapshotEnabled = $context['enabled'];
        $actualEnabled = array_keys(array_filter($flags));
        sort($snapshotEnabled);
        sort($actualEnabled);
        if ($expected !== $actual || $snapshotEnabled !== $actualEnabled) {
            throw new \RuntimeException('Feature activation changed; re-plan before applying.');
        }

        return $flags;
    }

    /** @return array<string,mixed> */
    private function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        if (is_link($path)) {
            throw new \RuntimeException('Refusing to mutate symbolic-linked module activation config.');
        }

        $configured = require $path;
        if (!is_array($configured)) {
            throw new \RuntimeException('Module activation config must return an array.');
        }

        $normalized = [];
        foreach ($configured as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param Closure(array<string,mixed>):array<string,mixed> $change
     * @phpstan-param Context $context
     */
    private function mutate(Closure $change, array $context): string
    {
        $path = $this->application->configPath('modules.php');
        $directory = dirname($path);
        if (is_link($directory)) {
            throw new \RuntimeException('Refusing symbolic-linked module activation directory.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create config directory "%s".', $directory));
        }

        $lockPath = $path . '.lock';
        if (is_link($lockPath)) {
            throw new \RuntimeException('Refusing symbolic-linked module activation lock.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open module activation lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire safe module activation lock.');
            }

            return $this->writeUnderLock($path, $change, $context);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function restoreConfig(string $path, string $temporary, ?string $original, \Throwable $failure): void
    {
        if ($original === null) {
            if (!unlink($path)) {
                throw new \RuntimeException('Unable to remove stale module activation config.', previous: $failure);
            }
        } elseif (file_put_contents($temporary, $original, LOCK_EX) !== strlen($original)
            || !rename($temporary, $path)
        ) {
            throw new \RuntimeException('Unable to restore module activation config after Composer drift.', previous: $failure);
        }
        new ConfigCacheManager($this->application)->clear();
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,bool> $changes
     * @phpstan-param Context $context
     * @param list<string> $features
     * @return array<string,mixed>
     */
    private function updateFeatureFlags(array $config, array $changes, array $context, string $operation, array $features): array
    {
        new ModuleFeatureCommandSnapshot($this->application)->assertCurrent($context);

        $flags = $this->assertFreshFeatureFlags($config, $context);
        foreach ($changes as $id => $active) {
            $flags[$id] = $active;
        }
        ksort($flags);
        $this->assertFeatureGraph($flags, $context, $operation, $features);
        $config['features'] = $flags;

        return $config;
    }

    /**
     * @param Closure(array<string,mixed>):array<string,mixed> $change
     * @phpstan-param Context $context
     */
    private function writeUnderLock(string $path, Closure $change, array $context): string
    {
        $directory = dirname($path);
        $previous = $this->load($path);
        $original = is_file($path) ? file_get_contents($path) : null;
        if ($original === false) {
            throw new \RuntimeException('Unable to preserve module activation config.');
        }
        $config = $change($previous);
        if ($config === $previous) {
            // A repeat activation with an identical feature topology must not
            // republish the file or invalidate unrelated compiled config.
            return $path;
        }

        $temporary = tempnam($directory, '.foundation-modules-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to allocate module activation staging file.');
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($config, true)
            . ";\n";

        $published = false;

        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
                throw new \RuntimeException('Unable to write module activation staging file.');
            }
            new ModuleFeatureCommandSnapshot($this->application)->assertCurrent($context);
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Unable to publish module activation config.');
            }
            $published = true;
            new ModuleFeatureCommandSnapshot($this->application)->assertCurrent($context, false);
        } catch (\Throwable $failure) {
            if ($published) {
                $this->restoreConfig($path, $temporary, $original, $failure);
            }

            throw $failure;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        new ConfigCacheManager($this->application)->clear();

        return $path;
    }
}
