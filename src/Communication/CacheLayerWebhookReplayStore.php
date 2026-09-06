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
    private AtomicCacheInterface $atomic;

    public function __construct(private CacheInterface $cache)
    {
        if (!$cache instanceof AtomicCacheProviderInterface || ($atomic = $cache->atomic()) === null) {
            throw new \LogicException(
                'Webhook replay protection requires a CacheLayer store with atomic cache capability.',
            );
        }

        $this->atomic = $atomic;
    }

    public function claim(string $namespace, string $deliveryId, int $ttlSeconds): bool
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Webhook replay TTL must be at least one second.');
        }

        $logical = json_encode([$namespace, $deliveryId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $key = FoundationCacheKey::security('wr', 'foundation.webhook.replay.v1', $logical);

        return $this->atomic->setIfAbsent($key, 1, $ttlSeconds);
    }
}
