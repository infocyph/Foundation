<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\AtomicCacheProviderInterface;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Cache\FoundationCacheKey;
use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;

final readonly class CacheLayerWebhookReplayStore implements WebhookReplayStore
{
    /** @var AtomicCacheInterface|(\Closure(): AtomicCacheInterface) */
    private AtomicCacheInterface|\Closure $atomic;

    /** @param CacheInterface|(\Closure(): CacheInterface) $cache */
    public function __construct(CacheInterface|\Closure $cache)
    {
        $this->atomic = $cache instanceof \Closure
            ? static fn(): AtomicCacheInterface => self::atomic($cache())
            : self::atomic($cache);
    }

    public function claim(string $namespace, string $deliveryId, int $ttlSeconds): bool
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Webhook replay TTL must be at least one second.');
        }

        $logical = json_encode([$namespace, $deliveryId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $key = FoundationCacheKey::security('wr', 'foundation.webhook.replay.v1', $logical);

        $atomic = $this->atomic instanceof \Closure ? ($this->atomic)() : $this->atomic;

        return $atomic->setIfAbsent($key, 1, $ttlSeconds);
    }

    private static function atomic(CacheInterface $cache): AtomicCacheInterface
    {
        if (!$cache instanceof AtomicCacheProviderInterface || ($atomic = $cache->atomic()) === null) {
            throw new \LogicException(
                'Webhook replay protection requires a CacheLayer store with atomic cache capability.',
            );
        }

        return $atomic;
    }
}
