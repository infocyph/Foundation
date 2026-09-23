<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigCacheManager;

/** @phpstan-import-type ModuleDefinition from ModuleCatalog */
final readonly class ModuleActivationManager
{
    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
    ) {}

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

    /** @phpstan-param ModuleDefinition $definition */
    private function assertMutable(array $definition): void
    {
        if (($definition['built_in'] ?? false) === true) {
            throw new \InvalidArgumentException(sprintf(
                'Module "%s" is built into Foundation and has no module activation lifecycle.',
                $definition['name'] ?? 'unknown',
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

        return $configured;
    }

    private function write(string $module, bool $enabled): string
    {
        $path = $this->application->configPath('modules.php');
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create config directory "%s".', $directory));
        }

        $config = $this->load($path);
        $capabilities = $config['capabilities'] ?? [];
        if (!is_array($capabilities)) {
            throw new \RuntimeException('modules.capabilities must be a capability map.');
        }
        $capabilities[$module] = $enabled;
        ksort($capabilities);
        $config['capabilities'] = $capabilities;

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
