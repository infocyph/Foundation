<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authorization\Grant\AccessGrant;
use Infocyph\Foundation\Auth\Authorization\Grant\GrantStoreInterface;

final readonly class DBLayerGrantStore extends ClockedDBLayerStore implements GrantStoreInterface
{
    public function grantsForPrincipal(string $principalId): array
    {
        return $this->allMapped(
            sprintf('SELECT * FROM %s WHERE principal_id = ?', $this->table('grants')),
            $this->mapGrant(...),
            [$principalId],
        );
    }

    public function revoke(string $grantId): void
    {
        $this->updateWhere('grants', ['revoked_at' => $this->now()], 'id = ? AND revoked_at IS NULL', [$grantId]);
    }

    public function save(AccessGrant $grant): void
    {
        $this->upsertRecord('grants', 'id', [
            'id' => $grant->id,
            'principal_id' => $grant->principalId,
            'permission' => $grant->permission,
            'resource_type' => $grant->resourceType,
            'resource_id' => $grant->resourceId,
            'expires_at' => $grant->expiresAt,
            'revoked_at' => $grant->revokedAt,
            'metadata' => DBLayerJson::encode($grant->metadata),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapGrant(array $row): AccessGrant
    {
        return new AccessGrant(
            id: $this->string($row['id'] ?? ''),
            principalId: $this->string($row['principal_id'] ?? ''),
            permission: $this->string($row['permission'] ?? ''),
            resourceType: $this->stringOrNull($row['resource_type'] ?? null),
            resourceId: $this->stringOrNull($row['resource_id'] ?? null),
            expiresAt: $this->intOrNull($row['expires_at'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
