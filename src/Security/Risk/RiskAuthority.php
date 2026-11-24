<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security\Risk;

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\AtomicCacheProviderInterface;
use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\CacheLayer\Cache\CacheInterface;

/**
 * Explicitly qualified shared security-state authority. The operator must
 * establish a single consistency domain across workers/hosts; an advertised
 * cache capability alone cannot prove that topology.
 *
 * @api
 */
final readonly class RiskAuthority
{
    public AtomicCacheInterface $atomic;

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function __construct(
        public CacheInterface $cache,
        #[\SensitiveParameter]
        private string $keySecret,
        bool $shared = false,
    ) {
        if (strlen($keySecret) < 32) {
            throw new \InvalidArgumentException('Risk authority requires a dedicated secret of at least 32 bytes.');
        }
        if (!$shared || !$cache instanceof AuthenticationStateCacheInterface
            || !$cache->isAuthoritative() || $cache->isFailOpen() || !$cache->hasPayloadIntegrity()
            || !$cache instanceof AtomicCacheProviderInterface || ($atomic = $cache->atomic()) === null
        ) {
            throw new \LogicException('Risk security state requires qualified shared, authoritative, integrity-protected, fail-closed atomic cache storage.');
        }
        $this->atomic = $atomic;
    }

    /** Domain-separated, unambiguous PSR-safe HMAC key for security state. */
    public function key(string $domain, string ...$parts): string
    {
        $material = "foundation-risk-authority-v1\0" . pack('N', strlen($domain)) . $domain;
        foreach ($parts as $part) {
            $material .= pack('N', strlen($part)) . $part;
        }
        $digest = hash_hmac('sha256', $material, $this->keySecret, true);

        return 'ra.' . rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }
}
