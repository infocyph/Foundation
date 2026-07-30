<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session\Store;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Session\SessionPayload;
use Infocyph\Foundation\Session\SessionStoreInterface;

final readonly class DatabaseSessionStore implements SessionStoreInterface
{
    public function __construct(
        private Connection $connection,
        private string $table = 'sessions',
    ) {}

    public function delete(string $id): void
    {
        $this->connection->table($this->table)->where('id', '=', $id)->delete();
    }

    public function load(string $id, int $now): ?SessionPayload
    {
        $row = $this->connection->table($this->table)->where('id', '=', $id)->first();
        if ($row === null) {
            return null;
        }

        $expiresAt = $row['expires_at'] ?? null;
        $payload = $row['payload'] ?? null;
        if (!is_numeric($expiresAt) || !is_string($payload)) {
            $this->delete($id);

            return null;
        }
        if ((int) $expiresAt <= $now) {
            $this->delete($id);

            return null;
        }

        $decoded = SessionPayload::fromJson($payload);
        if ($decoded === null) {
            $this->delete($id);

            return null;
        }

        return $decoded;
    }

    public function prune(int $now, int $limit = 1_000): int
    {
        $ids = $this->connection->table($this->table)
            ->where('expires_at', '<=', $now)
            ->limit(max(1, $limit))
            ->pluck('id');
        $normalized = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $normalized[] = $id;
            }
        }

        return $normalized === []
            ? 0
            : $this->connection->table($this->table)->whereIn('id', $normalized)->delete();
    }

    public function save(string $id, SessionPayload $payload): void
    {
        $this->connection->table($this->table)->upsert([
            'id' => $id,
            'payload' => $payload->toJson(),
            'expires_at' => $payload->expiresAt,
        ], ['id'], ['payload', 'expires_at']);
    }
}
