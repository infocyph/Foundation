<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;

final readonly class DBLayerSessionStore extends DBLayerStore implements SessionStoreInterface
{
    public function create(AuthSession $session): void
    {
        $this->insertRecord('sessions', [
            'id' => $session->id,
            'account_id' => $session->accountId,
            'device_id' => $session->deviceId,
            'created_at' => $session->createdAt,
            'last_seen_at' => $session->lastSeenAt,
            'expires_at' => $session->expiresAt,
            'recent_auth_at' => $session->recentAuthAt,
            'metadata' => DBLayerJson::encode($session->metadata),
        ]);
    }

    public function find(string $sessionId): ?AuthSession
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('sessions')),
            $this->mapSession(...),
            [$sessionId],
        );
    }

    public function revoke(string $sessionId): void
    {
        $this->deleteWhere('sessions', 'id = ?', [$sessionId]);
    }

    public function revokeAllForAccount(string $accountId, ?string $exceptSessionId = null): void
    {
        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $this->deleteWhere('sessions', 'account_id = ? AND id <> ?', [$accountId, $exceptSessionId]);

            return;
        }

        $this->deleteWhere('sessions', 'account_id = ?', [$accountId]);
    }

    public function rotate(string $sessionId, AuthSession $replacement): void
    {
        $this->revoke($sessionId);
        $this->create($replacement);
    }

    public function touch(string $sessionId, int $lastSeenAt): void
    {
        $this->updateWhere('sessions', ['last_seen_at' => $lastSeenAt], 'id = ?', [$sessionId]);
    }

    /**
     * @param array<string, mixed> $row
     */
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
