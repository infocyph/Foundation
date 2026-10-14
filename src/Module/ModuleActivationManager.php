<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Closure;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigCacheManager;

/**
 * @phpstan-import-type ResolvedModule from ModuleCatalog
 * @phpstan-import-type Context from ModuleFeatureResolver
 */
final readonly class ModuleActivationManager
{
    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
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

        return $this->mutate(
            fn(array $config): array => $this->updateFeatureFlags(
                $config,
                $plan['effects']['activate'],
                $context['enabled'],
            ),
        );
    }

    public function disable(string $module): string
    {
        $definition = $this->catalog->resolve($module);
        $this->assertMutable($definition);
        $this->assertDisableSafe($definition['name']);

        return $this->write($definition['name'], false);
    }

    public function enable(string $module): string
    {
        $definition = $this->catalog->resolve($module);
        $this->assertMutable($definition);
        $state = array_find(
            new ModuleStateResolver($this->application, $this->catalog)->all(),
            static fn(array $candidate): bool => $candidate['name'] === $definition['name'],
        );
        if (!is_array($state)) {
            throw new \RuntimeException(sprintf('Unable to resolve module "%s".', $definition['name']));
        }
        if (!$state['installed']) {
            throw new \RuntimeException(sprintf(
                'Module "%s" is not installed; install it before enabling.',
                $definition['name'],
            ));
        }
        if (!$state['dependencies_satisfied']) {
            throw new \RuntimeException(sprintf(
                'Module "%s" cannot be enabled: %s',
                $definition['name'],
                implode(' ', $state['blockers']),
            ));
        }

        return $this->write($definition['name'], true);
    }

    private function assertDisableSafe(string $module): void
    {
        $states = new ModuleStateResolver($this->application, $this->catalog)->all();
        $dependents = [];

        foreach ($states as $state) {
            if (!$state['enabled'] || $state['name'] === $module) {
                continue;
            }
            foreach ($state['dependencies']['active'] as $dependency) {
                if ($dependency['type'] === 'module' && $dependency['target'] === $module) {
                    $dependents[] = sprintf('%s: %s', $state['name'], $dependency['reason']);
                }
            }
        }

        if ($dependents !== []) {
            throw new \RuntimeException(sprintf(
                'Module "%s" cannot be disabled while active dependents require it: %s',
                $module,
                implode('; ', array_values(array_unique($dependents))),
            ));
        }
    }

    /** @phpstan-param ResolvedModule $definition */
    private function assertMutable(array $definition): void
    {
        if (($definition['built_in'] ?? false) === true) {
            throw new \InvalidArgumentException(sprintf(
                'Module "%s" is built into Foundation and has no module activation lifecycle.',
                $definition['name'],
            ));
        }
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
     */
    private function mutate(Closure $change): string
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

            return $this->writeUnderLock($path, $change);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function updateCapability(array $config, string $module, bool $enabled): array
    {
        $capabilities = $config['capabilities'] ?? [];
        if (!is_array($capabilities)) {
            throw new \RuntimeException('modules.capabilities must be a capability map.');
        }
        $capabilities[$module] = $enabled;
        ksort($capabilities);
        $config['capabilities'] = $capabilities;

        return $config;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,bool> $changes
     * @param list<string> $enabled
     * @return array<string,mixed>
     */
    private function updateFeatureFlags(array $config, array $changes, array $enabled): array
    {
        $flags = $config['features'] ?? [];
        if (!is_array($flags)) {
            throw new \RuntimeException('modules.features must be a feature map.');
        }
        foreach ($flags as $id => $active) {
            if (!is_string($id) || !is_bool($active)) {
                throw new \RuntimeException('modules.features must contain boolean feature flags.');
            }
            if ($active !== in_array($id, $enabled, true)) {
                throw new \RuntimeException('Feature activation changed; re-plan before applying.');
            }
        }

        foreach ($changes as $id => $active) {
            $flags[$id] = $active;
        }
        ksort($flags);
        $config['features'] = $flags;

        return $config;
    }

    private function write(string $module, bool $enabled): string
    {
        return $this->mutate(
            fn(array $config): array => $this->updateCapability($config, $module, $enabled),
        );
    }

    /**
     * @param Closure(array<string,mixed>):array<string,mixed> $change
     */
    private function writeUnderLock(string $path, Closure $change): string
    {
        $directory = dirname($path);
        $config = $change($this->load($path));
        $temporary = tempnam($directory, '.foundation-modules-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to allocate module activation staging file.');
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($config, true)
            . ";\n";

        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write module activation staging file.');
            }
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Unable to publish module activation config.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        new ConfigCacheManager($this->application)->clear();

        return $path;
    }
}
