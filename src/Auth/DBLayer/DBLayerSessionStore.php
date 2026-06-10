<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

use Infocyph\AuthLayer\Authentication\Session\AuthSession;
use Infocyph\AuthLayer\Contract\Storage\SessionStoreInterface;

final readonly class DBLayerSessionStore extends DBLayerStore implements SessionStoreInterface
{
    public function create(AuthSession $session): void
    {
        $this->execute(
            sprintf('INSERT INTO %s (id, account_id, device_id, created_at, last_seen_at, expires_at, recent_auth_at, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $this->table('sessions')),
            [
                $session->id,
                $session->accountId,
                $session->deviceId,
                $session->createdAt,
                $session->lastSeenAt,
                $session->expiresAt,
                $session->recentAuthAt,
                DBLayerJson::encode($session->metadata),
            ],
        );
    }

    public function find(string $sessionId): ?AuthSession
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('sessions')),
            [$sessionId],
        );

        return $row === null ? null : $this->mapSession($row);
    }

    public function revoke(string $sessionId): void
    {
        $this->execute(
            sprintf('DELETE FROM %s WHERE id = ?', $this->table('sessions')),
            [$sessionId],
        );
    }

    public function revokeAllForAccount(string $accountId, ?string $exceptSessionId = null): void
    {
        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $this->execute(
                sprintf('DELETE FROM %s WHERE account_id = ? AND id <> ?', $this->table('sessions')),
                [$accountId, $exceptSessionId],
            );

            return;
        }

        $this->execute(
            sprintf('DELETE FROM %s WHERE account_id = ?', $this->table('sessions')),
            [$accountId],
        );
    }

    public function rotate(string $sessionId, AuthSession $replacement): void
    {
        $this->revoke($sessionId);
        $this->create($replacement);
    }

    public function touch(string $sessionId, int $lastSeenAt): void
    {
        $this->execute(
            sprintf('UPDATE %s SET last_seen_at = ? WHERE id = ?', $this->table('sessions')),
            [$lastSeenAt, $sessionId],
        );
    }

    private function mapSession(array $row): AuthSession
    {
        return new AuthSession(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            deviceId: $this->stringOrNull($row['device_id'] ?? null),
            createdAt: $this->int($row['created_at'] ?? 0),
            lastSeenAt: $this->int($row['last_seen_at'] ?? 0),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            recentAuthAt: $this->intOrNull($row['recent_auth_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
