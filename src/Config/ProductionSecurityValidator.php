<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\Foundation\Auth\Driver\AuthCacheDriver;

final readonly class ProductionSecurityValidator
{
    /** @var list<string> */
    private const array DISTRIBUTED_CACHE_DRIVERS = [
        'memcache',
        'mongodb',
        'pdo',
        'redis',
        'redis_cluster',
        'scylladb',
        'valkey',
    ];

    /** @var list<string> */
    private const array DISTRIBUTED_LOCK_DRIVERS = [
        'memcache',
        'pdo',
        'redis',
        'valkey',
    ];

    /** @var list<string> */
    private const array UNSAFE_PRODUCTION_CACHE_DRIVERS = [
        'memory',
        'null_store',
        'weak_map',
    ];

    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function validate(): array
    {
        $issues = [];
        $topology = $this->topology($issues);
        $this->validatePasswordPolicy($issues);
        $this->validateAuthState($issues, $topology);
        $this->validateLockTopology($issues, $topology);

        return $issues;
    }

    /** @param list<ConfigIssue> $issues */
    private function topology(array &$issues): DeploymentTopology
    {
        $configured = $this->config->get('app.topology', DeploymentTopology::SINGLE_NODE->value);
        if (!is_string($configured) || DeploymentTopology::tryFrom(strtolower(trim($configured))) === null) {
            $issues[] = new ConfigIssue(
                'app.topology must be one of: single_node, distributed.',
                'app.topology',
            );

            return DeploymentTopology::SINGLE_NODE;
        }

        return DeploymentTopology::from(strtolower(trim($configured)));
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
    private function validateAuthState(array &$issues, DeploymentTopology $topology): void
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
        if ($default === null) {
            $issues[] = new ConfigIssue(
                'cache.default must select a CacheLayer store for production authentication state.',
                'cache.default',
            );

            return;
        }

        $driver = $this->storeDriver($default);
        if ($driver === null) {
            $issues[] = new ConfigIssue(
                sprintf('cache.stores.%s must be configured for production authentication state.', $default),
                'cache.stores.' . $default,
            );
        } elseif (in_array($driver, self::UNSAFE_PRODUCTION_CACHE_DRIVERS, true)) {
            $issues[] = new ConfigIssue(
                sprintf('cache.stores.%s uses "%s", which cannot preserve production authentication state across requests.', $default, $driver),
                'cache.stores.' . $default . '.driver',
            );
        } elseif ($topology === DeploymentTopology::DISTRIBUTED && !in_array($driver, self::DISTRIBUTED_CACHE_DRIVERS, true)) {
            $issues[] = new ConfigIssue(
                sprintf('cache.stores.%s uses node-local driver "%s"; distributed authentication requires a shared cache store.', $default, $driver),
                'cache.stores.' . $default . '.driver',
            );
        }

        $this->validateAtomicCounter($issues);
        $this->validateOtpReplayStore($issues, $topology, $default);
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
    private function validateOtpReplayStore(array &$issues, DeploymentTopology $topology, string $default): void
    {
        if ($this->config->get('auth.drivers.mfa', 'simple') !== 'otp') {
            return;
        }

        $configured = $this->string($this->config->get('auth.otp.replay.store'));
        $store = $configured ?? $default;
        $driver = $this->storeDriver($store);
        if ($driver === null) {
            return;
        }

        if (in_array($driver, self::UNSAFE_PRODUCTION_CACHE_DRIVERS, true)) {
            $issues[] = new ConfigIssue(
                sprintf('OTP replay store "%s" uses "%s", which is not durable production replay state.', $store, $driver),
                'auth.otp.replay.store',
            );
        } elseif ($topology === DeploymentTopology::DISTRIBUTED && !in_array($driver, self::DISTRIBUTED_CACHE_DRIVERS, true)) {
            $issues[] = new ConfigIssue(
                sprintf('OTP replay store "%s" is node-local; distributed MFA requires shared replay state.', $store),
                'auth.otp.replay.store',
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateLockTopology(array &$issues, DeploymentTopology $topology): void
    {
        if ($topology !== DeploymentTopology::DISTRIBUTED) {
            return;
        }

        $driver = $this->effectiveLockDriver();
        if (!in_array($driver, self::DISTRIBUTED_LOCK_DRIVERS, true)) {
            $issues[] = new ConfigIssue(
                'Distributed topology requires a Redis, Valkey, Memcached, or shared PDO coordination lock, either explicitly or through the selected CacheLayer store.',
                'cache.lock',
            );
        }
    }

    private function effectiveLockDriver(): ?string
    {
        $explicit = $this->normalizeDriver($this->config->get('cache.lock.driver'));
        if ($explicit !== null) {
            return $explicit;
        }

        $store = $this->string($this->config->get('cache.lock.store'))
            ?? $this->string($this->config->get('cache.default'));
        if ($store === null) {
            return null;
        }

        return match ($this->storeDriver($store)) {
            'memcache' => 'memcache',
            'pdo' => 'pdo',
            'redis' => 'redis',
            'valkey' => 'valkey',
            default => null,
        };
    }

    private function storeDriver(string $name): ?string
    {
        $definition = $this->config->get('cache.stores.' . $name);
        if (!is_array($definition)) {
            return null;
        }

        return $this->normalizeDriver($definition['driver'] ?? $name);
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
