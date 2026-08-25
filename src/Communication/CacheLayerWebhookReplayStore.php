<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;

final readonly class CacheLayerWebhookReplayStore implements WebhookReplayStore
{
    public function __construct(
        private CacheInterface $cache,
        private LockProviderInterface $locks,
    ) {}

    public function claim(string $namespace, string $deliveryId, int $ttlSeconds): bool
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Webhook replay TTL must be at least one second.');
        }

        $key = 'foundation:webhook:replay:' . hash('sha256', $namespace . "\0" . $deliveryId);
        $handle = $this->locks->acquire(
            'webhook-replay:' . hash('sha256', $key),
            waitSeconds: 1.0,
            leaseSeconds: 5.0,
        );
        if ($handle === null) {
            throw new \RuntimeException('Webhook replay coordination lock could not be acquired.');
        }

        try {
            if ($this->cache->has($key)) {
                return false;
            }
            if (!$this->cache->set($key, 1, $ttlSeconds)) {
                throw new \RuntimeException('Webhook replay claim could not be persisted.');
            }

            return true;
        } finally {
            $this->locks->release($handle);
        }
    }
}
