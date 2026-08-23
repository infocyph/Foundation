<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\Foundation\Auth\Driver\AuthCacheDriver;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class ProductionSecurityValidator
{
    private SharedStateTopology $state;

    public function __construct(private ConfigRepository $config)
    {
        $this->state = new SharedStateTopology($config);
    }

    /** @return list<ConfigIssue> */
    public function validate(): array
    {
        $issues = [];
        $this->validateTopology($issues);
        $this->validatePasswordPolicy($issues);
        $this->validateAuthState($issues);
        $this->validateAtomicCounter($issues);
        $this->validateLockTopology($issues);

        return $issues;
    }

    /** @param list<ConfigIssue> $issues */
    private function validateTopology(array &$issues): void
    {
        $configured = $this->config->get('app.topology', DeploymentTopology::SINGLE_NODE->value);
        if (!is_string($configured) || DeploymentTopology::tryFrom(strtolower(trim($configured))) === null) {
            $issues[] = new ConfigIssue(
                'app.topology must be one of: single_node, distributed.',
                'app.topology',
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validatePasswordPolicy(array &$issues): void
    {
        $minimum = $this->integer($this->config->get('auth.password_policy.min_length', 12));
        $maximum = $this->integer($this->config->get('auth.password_policy.max_length', 1024));

        if ($minimum === null || $minimum < 12) {
            $issues[] = new ConfigIssue(
                'auth.password_policy.min_length must be at least 12 in production.',
                'auth.password_policy.min_length',
            );
        }
        if ($maximum === null || $maximum < 12 || $maximum > 4096) {
            $issues[] = new ConfigIssue(
                'auth.password_policy.max_length must be between 12 and 4096 in production.',
                'auth.password_policy.max_length',
            );
        }
        if ($minimum !== null && $maximum !== null && $maximum < $minimum) {
            $issues[] = new ConfigIssue(
                'auth.password_policy.max_length must be greater than or equal to auth.password_policy.min_length.',
                'auth.password_policy.max_length',
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateAuthState(array &$issues): void
    {
        $cacheDriver = $this->config->get('auth.drivers.cache', AuthCacheDriver::ARRAY->value);
        if ($cacheDriver === AuthCacheDriver::ARRAY->value) {
            $issues[] = new ConfigIssue(
                'auth.drivers.cache must use CacheLayer-backed state in production; array state is process-local.',
                'auth.drivers.cache',
            );

            return;
        }
        if ($cacheDriver !== AuthCacheDriver::CACHE->value) {
            return;
        }

        $default = $this->string($this->config->get('cache.default'));
        if ($default === null || !$this->config->has('cache.stores.' . $default)) {
            $issues[] = new ConfigIssue(
                $default === null
                    ? 'cache.default must select a CacheLayer store for production authentication state.'
                    : sprintf('cache.stores.%s must be configured for production authentication state.', $default),
                $default === null ? 'cache.default' : 'cache.stores.' . $default,
            );

            return;
        }

        try {
            $this->state->assertCacheStore(
                $default,
                'Production authentication state and WebAuthn challenges',
                $this->state->requiredSecurityScope(),
            );
        } catch (ConfigurationException $exception) {
            $issues[] = new ConfigIssue($exception->getMessage(), 'cache.stores.' . $default);
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateAtomicCounter(array &$issues): void
    {
        $counter = $this->string($this->config->get('cache.default_counter'));
        if ($counter === null) {
            $issues[] = new ConfigIssue(
                'cache.default_counter must select an atomic Redis/Valkey counter for production authentication lockouts.',
                'cache.default_counter',
            );

            return;
        }

        $definition = $this->config->get('cache.counters.' . $counter);
        $driver = is_array($definition) ? $this->normalizeDriver($definition['driver'] ?? null) : null;
        if (!in_array($driver, ['redis', 'valkey'], true)) {
            $issues[] = new ConfigIssue(
                sprintf('cache.counters.%s must use Redis or Valkey for atomic authentication lockouts.', $counter),
                'cache.counters.' . $counter,
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateLockTopology(array &$issues): void
    {
        if (DeploymentTopology::resolve($this->config) !== DeploymentTopology::DISTRIBUTED) {
            return;
        }

        $scope = $this->state->cacheStoreCoordinationScope();
        if (!$this->state->satisfies($scope, SharedStateTopology::CLUSTER)) {
            $issues[] = new ConfigIssue(
                sprintf(
                    'Distributed topology requires cluster-visible cache coordination; configured coordination is %s-visible.',
                    $scope,
                ),
                'cache.lock',
            );
        }
    }

    private function normalizeDriver(mixed $driver): ?string
    {
        if (!is_string($driver) || trim($driver) === '') {
            return null;
        }

        return match (strtolower(trim($driver))) {
            'array' => 'memory',
            'memcached' => 'memcache',
            'null' => 'null_store',
            'scylla' => 'scylladb',
            default => strtolower(trim($driver)),
        };
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/D', $value) === 1
            ? (int) $value
            : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
