<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security\Risk;

use Psr\Cache\CacheException as PoolException;
use Psr\SimpleCache\CacheException;

/**
 * Shared bounded admission slots for isolated authentication workers.
 *
 * Live slots deliberately have no expiry: cache lease expiry cannot prove
 * that a synchronous password verifier has stopped consuming resources.
 * Missing slots deny admission. Provisioning is an offline operator action.
 *
 * @api
 */
final readonly class RiskHashBulkhead
{
    private const string FREE = 'free';

    /** @var list<string> */
    private array $slots;

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function __construct(
        private RiskAuthority $authority,
        string $pool,
        int $capacity,
        bool $nonEvictingIsolatedPool = false,
    ) {
        if (!$nonEvictingIsolatedPool) {
            throw new \LogicException('Hash admission requires a dedicated non-evicting authority and isolated worker pool.');
        }
        if ($pool === '' || strlen($pool) > 128 || $capacity < 1 || $capacity > 256) {
            throw new \InvalidArgumentException('Invalid hash capacity policy.');
        }
        $slots = [];
        for ($slot = 0; $slot < $capacity; ++$slot) {
            $slots[] = $authority->key('hash-slot', $pool, (string) $slot);
        }
        $this->slots = $slots;
    }

    /**
     * One bounded scan, no spinning or queueing. Null means unavailable/full.
     *
     * @return array{slot:int,owner:string}|null
     * @throws \Random\RandomException
     */
    public function acquire(): ?array
    {
        $owner = bin2hex(random_bytes(32));

        try {
            foreach ($this->slots as $slot => $key) {
                if ($this->authority->atomic->compareAndSet($key, self::FREE, $owner)) {
                    return ['slot' => $slot, 'owner' => $owner];
                }
            }
        } catch (CacheException|PoolException) {
            return null;
        }

        return null;
    }

    /**
     * Provision missing slots only while the entire pool is verified drained.
     * Never run this in request handling, after cache loss, or on a timer.
     * Existing ownership is never overwritten.
     */
    public function provisionDrainedPool(): bool
    {
        try {
            return array_all(
                $this->slots,
                fn(string $key): bool => $this->authority->atomic->setIfAbsent($key, self::FREE)
                    || $this->authority->cache->get($key) === self::FREE,
            );
        } catch (CacheException|PoolException) {
            return false;
        }
    }

    /**
     * Release only the matching owner, and only after work has actually ended.
     * A stale token cannot release a newer owner's slot.
     *
     * @param array{slot:int,owner:string} $lease
     */
    public function release(#[\SensitiveParameter] array $lease): bool
    {
        $key = $this->slots[$lease['slot']] ?? null;
        if ($key === null || preg_match('/\A[a-f0-9]{64}\z/D', $lease['owner']) !== 1) {
            return false;
        }

        try {
            return $this->authority->atomic->compareAndSet($key, $lease['owner'], self::FREE);
        } catch (CacheException|PoolException) {
            return false;
        }
    }
}
