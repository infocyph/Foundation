<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config\Internal;

use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\DeploymentTopology;
use Infocyph\Foundation\Config\SharedStateTopology;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class RuntimeOperationsValidator
{
    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function validate(): array
    {
        return [
            ...$this->historyEnabled(),
            ...$this->paths(),
            ...$this->stateSurface('maintenance'),
            ...$this->stateSurface('runtime_control'),
            ...$this->allowedString(
                'operations.runtime_registry.visibility',
                $this->config->get('operations.runtime_registry.visibility', 'host'),
                ['host', 'shared'],
            ),
            ...$this->limits(),
        ];
    }

    /**
     * @param list<string> $allowed
     * @return list<ConfigIssue>
     */
    private function allowedString(string $key, mixed $value, array $allowed): array
    {
        return is_string($value) && in_array($value, $allowed, true)
            ? []
            : [new ConfigIssue(
                sprintf('%s must be one of: %s.', $key, implode(', ', $allowed)),
                $key,
            )];
    }

    /** @return list<ConfigIssue> */
    private function historyEnabled(): array
    {
        return is_bool($this->config->get('operations.history.enabled', false))
            ? []
            : [new ConfigIssue(
                'operations.history.enabled must be true or false.',
                'operations.history.enabled',
            )];
    }

    /** @return list<ConfigIssue> */
    private function limits(): array
    {
        $issues = [];
        $maxBytes = $this->config->get('operations.history.max_bytes', 16_777_216);
        if (!is_int($maxBytes) || $maxBytes < 1) {
            $issues[] = new ConfigIssue(
                'operations.history.max_bytes must be an integer of at least 1.',
                'operations.history.max_bytes',
            );
        }

        $retained = $this->config->get('operations.history.retained_files', 7);
        if (!is_int($retained) || $retained < 0 || $retained > 100) {
            $issues[] = new ConfigIssue(
                'operations.history.retained_files must be an integer between 0 and 100.',
                'operations.history.retained_files',
            );
        }

        $stale = $this->config->get('operations.runtime_registry.stale_seconds', 15);
        if (!is_int($stale) || $stale < 1 || $stale > 3_600) {
            $issues[] = new ConfigIssue(
                'operations.runtime_registry.stale_seconds must be an integer between 1 and 3600.',
                'operations.runtime_registry.stale_seconds',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function operationalCacheState(string $surface, mixed $configuredStore): array
    {
        $store = is_string($configuredStore) && trim($configuredStore) !== ''
            ? trim($configuredStore)
            : $this->config->get('cache.default');
        $key = 'operations.' . $surface . '.store';
        if (!is_string($store) || trim($store) === '') {
            return [new ConfigIssue(
                sprintf('%s requires a configured cache store.', 'operations.' . $surface),
                $key,
            )];
        }

        $store = trim($store);
        if (!$this->config->has('cache.stores.' . $store)) {
            return [new ConfigIssue(
                sprintf('cache.stores.%s must exist for cache-backed %s.', $store, str_replace('_', ' ', $surface)),
                'cache.stores.' . $store,
            )];
        }

        $topology = new SharedStateTopology($this->config);
        $required = DeploymentTopology::resolve($this->config) === DeploymentTopology::DISTRIBUTED
            ? SharedStateTopology::CLUSTER
            : SharedStateTopology::HOST;

        try {
            $topology->assertCacheStore(
                $store,
                $surface === 'runtime_control' ? 'Runtime control' : 'Maintenance state',
                $required,
                $surface === 'runtime_control',
            );
        } catch (ConfigurationException $exception) {
            return [new ConfigIssue($exception->getMessage(), 'cache.stores.' . $store)];
        }

        return [];
    }

    /** @return list<ConfigIssue> */
    private function paths(): array
    {
        $issues = [];
        foreach ([
            'operations.history.path' => 'storage/logs/executions.jsonl',
            'operations.maintenance.path' => 'storage/framework/maintenance.json',
            'operations.runtime_control.path' => 'storage/framework/runtime-control.json',
            'operations.runtime_registry.path' => 'storage/framework/runtime',
        ] as $key => $default) {
            $value = $this->config->get($key, $default);
            if (!is_string($value) || trim($value) === '') {
                $issues[] = new ConfigIssue($key . ' must be a non-empty application path.', $key);
            }
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function stateSurface(string $surface): array
    {
        $prefix = 'operations.' . $surface;
        $issues = $this->allowedString(
            $prefix . '.driver',
            $this->config->get($prefix . '.driver', 'file'),
            ['file', 'cache'],
        );
        $key = $this->config->get(
            $prefix . '.key',
            $surface === 'maintenance' ? 'foundation:maintenance' : 'foundation:runtime-control',
        );
        if (!is_string($key) || trim($key) === '') {
            $issues[] = new ConfigIssue($prefix . '.key must be a non-empty cache key.', $prefix . '.key');
        }

        $store = $this->config->get($prefix . '.store');
        if ($store !== null && (!is_string($store) || trim($store) === '')) {
            $issues[] = new ConfigIssue(
                $prefix . '.store must be null or a non-empty configured cache store name.',
                $prefix . '.store',
            );
        }

        if ($this->config->get($prefix . '.driver', 'file') === 'cache') {
            array_push($issues, ...$this->operationalCacheState($surface, $store));
        }

        return $issues;
    }
}
