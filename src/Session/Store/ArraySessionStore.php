<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session\Store;

use Infocyph\Foundation\Session\SessionPayload;
use Infocyph\Foundation\Session\SessionStoreInterface;

final class ArraySessionStore implements SessionStoreInterface
{
    /** @var array<string, SessionPayload> */
    private array $sessions = [];

    public function delete(string $id): void
    {
        unset($this->sessions[$id]);
    }

    public function load(string $id, int $now): ?SessionPayload
    {
        $payload = $this->sessions[$id] ?? null;
        if ($payload !== null && $payload->expiresAt <= $now) {
            unset($this->sessions[$id]);

            return null;
        }

        return $payload;
    }

    public function prune(int $now, int $limit = 1_000): int
    {
        $pruned = 0;
        foreach ($this->sessions as $id => $payload) {
            if ($payload->expiresAt > $now) {
                continue;
            }

            unset($this->sessions[$id]);
            if (++$pruned >= $limit) {
                break;
            }
        }

        return $pruned;
    }

    public function save(string $id, SessionPayload $payload): void
    {
        $this->sessions[$id] = $payload;
    }
}
