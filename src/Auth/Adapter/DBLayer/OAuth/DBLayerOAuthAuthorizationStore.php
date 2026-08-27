<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerJson;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorization;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationStoreInterface;

final readonly class DBLayerOAuthAuthorizationStore extends DBLayerStore implements OAuthAuthorizationStoreInterface
{
    public function find(string $authorizationId): ?OAuthAuthorization
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('oauthAuthorizations')),
            $this->mapAuthorization(...),
            [$authorizationId],
        );
    }

    public function recent(int $limit = 100, ?string $clientId = null): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('OAuth authorization list limit must be between 1 and 500.');
        }
        $sql = sprintf('SELECT * FROM %s', $this->table('oauthAuthorizations'));
        $bindings = [];
        if (is_string($clientId) && $clientId !== '') {
            $sql .= ' WHERE client_id = ?';
            $bindings[] = $clientId;
        }
        $sql .= sprintf(' ORDER BY created_at DESC, id ASC LIMIT %d', $limit);

        return array_map($this->mapAuthorization(...), $this->all($sql, $bindings));
    }

    public function revoke(string $authorizationId, int $revokedAt): bool
    {
        return $this->connection()->execute(
            sprintf(
                'UPDATE %s SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
                $this->table('oauthAuthorizations'),
            ),
            [$revokedAt, $authorizationId],
        )->rowCount() === 1;
    }

    public function save(OAuthAuthorization $authorization): void
    {
        $this->upsertRecord('oauthAuthorizations', 'id', [
            'id' => $authorization->id,
            'client_id' => $authorization->clientId,
            'account_id' => $authorization->accountId,
            'scopes' => DBLayerJson::encodeList($authorization->scopes),
            'audiences' => DBLayerJson::encodeList($authorization->audiences),
            'created_at' => $authorization->createdAt,
            'expires_at' => $authorization->expiresAt,
            'revoked_at' => $authorization->revokedAt,
            'metadata' => DBLayerJson::encode($authorization->metadata),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function mapAuthorization(array $row): OAuthAuthorization
    {
        return new OAuthAuthorization(
            id: $this->string($row['id'] ?? ''),
            clientId: $this->string($row['client_id'] ?? ''),
            accountId: $this->stringOrNull($row['account_id'] ?? null),
            scopes: DBLayerJson::decodeStringList($row['scopes'] ?? null),
            audiences: DBLayerJson::decodeStringList($row['audiences'] ?? null),
            createdAt: $this->int($row['created_at'] ?? 0),
            expiresAt: $this->intOrNull($row['expires_at'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
