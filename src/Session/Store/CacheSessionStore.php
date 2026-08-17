<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session\Store;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Session\SessionPayload;
use Infocyph\Foundation\Session\SessionStoreInterface;

final readonly class CacheSessionStore implements SessionStoreInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'foundation.session.',
    ) {}

    public function delete(string $id): void
    {
        $this->cache->delete($this->key($id));
    }

    public function load(string $id, int $now): ?SessionPayload
    {
        $value = $this->cache->get($this->key($id));
        $payload = is_array($value) ? SessionPayload::fromArray($value) : null;

        if ($payload !== null && $payload->expiresAt <= $now) {
            $this->delete($id);

            return null;
        }

        return $payload;
    }

    public function prune(int $now, int $limit = 1_000): int
    {
        unset($now, $limit);

        return 0;
    }

    public function save(string $id, SessionPayload $payload): void
    {
        $ttl = max(1, $payload->expiresAt - time());
        if (!$this->cache->set($this->key($id), $payload->toArray(), $ttl)) {
            throw new \RuntimeException('Unable to persist the browser session in the configured cache store.');
        }
    }

    private function key(string $id): string
    {
        return hash('sha256', $this->prefix . $id);
    }
}
