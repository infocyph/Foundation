<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security\Risk;

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\AtomicCacheProviderInterface;
use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Psr\Cache\CacheException as PoolException;
use Psr\SimpleCache\CacheException;

/**
 * Best-effort, fixed-window risk telemetry. Local adapters are usable but
 * cannot certify distributed absence or counts. Never use this as proof,
 * authorization or hash-capacity storage.
 *
 * @api
 */
final readonly class RiskTelemetry
{
    private const int MAX_COUNT = 1_000_000;

    private ?AtomicCacheInterface $atomic;

    private bool $qualified;

    /**
     * Shared is an operator assertion about the actual backend topology, not
     * an adapter-name heuristic. A false value still permits local telemetry.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private CacheInterface $cache,
        #[\SensitiveParameter]
        private string $keySecret,
        bool $shared = false,
        private int $windowSeconds = 60,
        private int $familiaritySeconds = 86_400,
        private int $concentrationAt = 10,
    ) {
        if (strlen($keySecret) < 32 || $windowSeconds < 1 || $windowSeconds > 3600
            || $familiaritySeconds < 60 || $familiaritySeconds > 2_592_000
            || $concentrationAt < 1 || $concentrationAt > self::MAX_COUNT
        ) {
            throw new \InvalidArgumentException('Invalid risk telemetry configuration.');
        }
        $this->atomic = $cache instanceof AtomicCacheProviderInterface ? $cache->atomic() : null;
        $this->qualified = $shared && $this->atomic !== null
            && $cache instanceof AuthenticationStateCacheInterface
            && $cache->isAuthoritative() && !$cache->isFailOpen();
    }

    /**
     * Positive observations can be useful even when incomplete. Reliable is
     * false for a local/unqualified backend, a failure, or corrupt state.
     *
     * @return array{familiar:?bool,failedSignatureAttempts:int,concentrated:bool,reliable:bool}
     * @throws \InvalidArgumentException
     */
    public function observe(RiskContext $context, int $now, ?string $account = null): array
    {
        $keys = $this->failureKeys($context, $now, $account);
        if ($context->ja4 !== null) {
            $keys['familiar'] = $this->key($context, 'familiar', $context->ja4);
        }

        $values = [];
        $reliable = $this->qualified;

        try {
            foreach ($this->cache->getMultiple(array_values($keys)) as $key => $value) {
                $values[$key] = $value;
            }
        } catch (CacheException|PoolException) {
            $reliable = false;
        }

        return $this->summarize($keys, $values, $reliable);
    }

    /**
     * Record one confirmed failed authentication outcome. Each dimension is
     * independently atomic; false means at least one update was unavailable.
     * Do not retry the entire attempt after partial success.
     *
     * @throws \InvalidArgumentException
     */
    public function recordFailure(RiskContext $context, int $now, ?string $account = null): bool
    {
        $keys = $this->failureKeys($context, $now, $account);
        if ($this->atomic === null) {
            return false;
        }
        $ttl = $this->windowSeconds - ($now % $this->windowSeconds) + 1;
        $complete = true;
        foreach ($keys as $key) {
            try {
                if (!$this->increment($this->atomic, $key, $ttl)) {
                    $complete = false;
                }
            } catch (CacheException|PoolException) {
                $complete = false;
            }
        }

        return $complete;
    }

    /** Call only after independently confirmed successful authentication. */
    public function recordSuccess(RiskContext $context): bool
    {
        if ($context->ja4 === null) {
            return true;
        }

        try {
            return $this->cache->set($this->key($context, 'familiar', $context->ja4), 1, $this->familiaritySeconds);
        } catch (CacheException|PoolException) {
            return false;
        }
    }

    /**
     * @return array<string,string>
     * @throws \InvalidArgumentException
     */
    private function failureKeys(RiskContext $context, int $now, ?string $account): array
    {
        if ($now < 0 || ($account !== null && ($account === '' || strlen($account) > 256
            || preg_match('/[\x00-\x1f\x7f]/', $account) !== 0))) {
            throw new \InvalidArgumentException('Invalid risk telemetry scope.');
        }
        $bucket = (string) intdiv($now, $this->windowSeconds);
        $keys = ['prefix' => $this->key($context, 'prefix', $context->prefix, $bucket)];
        if ($context->ja4 !== null) {
            $keys['signature'] = $this->key($context, 'signature', $context->ja4, $bucket);
        }
        if ($context->asn !== null) {
            $keys['asn'] = $this->key($context, 'asn', $context->asn, $bucket);
        }
        if ($account !== null) {
            $keys['account'] = $this->key($context, 'account', $account, $bucket);
        }

        return $keys;
    }

    /** @throws CacheException */
    private function increment(AtomicCacheInterface $atomic, string $key, int $ttl): bool
    {
        for ($attempt = 0; $attempt < 12; ++$attempt) {
            $current = $this->cache->get($key);
            if ($current === null) {
                if ($atomic->setIfAbsent($key, 1, $ttl)) {
                    return true;
                }

                continue;
            }
            if (!is_int($current) || $current < 1 || $current > self::MAX_COUNT) {
                return false;
            }
            if ($current === self::MAX_COUNT || $atomic->compareAndSet($key, $current, $current + 1, $ttl)) {
                return true;
            }
        }

        return false;
    }

    private function key(RiskContext $context, string $dimension, string $value, string $bucket = ''): string
    {
        $material = "foundation-risk-telemetry-v1\0" . $context->tenant . "\0" . $context->purpose
            . "\0" . $dimension . "\0" . $value . "\0" . $bucket;
        $digest = hash_hmac('sha256', $material, $this->keySecret, true);

        return 'rt.' . rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    /**
     * @param array<string,string> $keys
     * @param array<array-key,mixed> $values
     * @return array{familiar:?bool,failedSignatureAttempts:int,concentrated:bool,reliable:bool}
     */
    private function summarize(array $keys, array $values, bool $reliable): array
    {
        $familiar = null;
        $signature = 0;
        $concentrated = false;
        if (isset($keys['familiar'])) {
            $value = $values[$keys['familiar']] ?? null;
            if ($value === 1) {
                $familiar = true;
            } elseif ($value !== null) {
                $reliable = false;
            } elseif ($reliable) {
                $familiar = false;
            }
            unset($keys['familiar']);
        }
        foreach ($keys as $dimension => $key) {
            $value = $values[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if (!is_int($value) || $value < 1 || $value > self::MAX_COUNT) {
                $reliable = false;

                continue;
            }
            if ($dimension === 'signature' || $dimension === 'account') {
                $signature = max($signature, $value);
            } elseif ($value >= $this->concentrationAt) {
                $concentrated = true;
            }
        }

        return [
            'familiar' => $familiar === false && !$reliable ? null : $familiar,
            'failedSignatureAttempts' => $signature,
            'concentrated' => $concentrated,
            'reliable' => $reliable,
        ];
    }
}
