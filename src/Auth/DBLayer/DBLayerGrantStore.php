<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

use Infocyph\AuthLayer\Authorization\Grant\AccessGrant;
use Infocyph\AuthLayer\Authorization\Grant\GrantStoreInterface;
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerGrantStore extends DBLayerStore implements GrantStoreInterface
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        private ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

    public function grantsForPrincipal(string $principalId): array
    {
        return array_map(
            fn(array $row): AccessGrant => new AccessGrant(
                id: $this->string($row['id'] ?? ''),
                principalId: $this->string($row['principal_id'] ?? ''),
                permission: $this->string($row['permission'] ?? ''),
                resourceType: $this->stringOrNull($row['resource_type'] ?? null),
                resourceId: $this->stringOrNull($row['resource_id'] ?? null),
                expiresAt: $this->intOrNull($row['expires_at'] ?? null),
                revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
                metadata: DBLayerJson::decode($row['metadata'] ?? null),
            ),
            $this->all(
                sprintf('SELECT * FROM %s WHERE principal_id = ?', $this->table('grants')),
                [$principalId],
            ),
        );
    }

    public function revoke(string $grantId): void
    {
        $this->execute(
            sprintf('UPDATE %s SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', $this->table('grants')),
            [$this->clock->now(), $grantId],
        );
    }

    public function save(AccessGrant $grant): void
    {
        if ($this->first(
            sprintf('SELECT id FROM %s WHERE id = ?', $this->table('grants')),
            [$grant->id],
        ) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET principal_id = ?, permission = ?, resource_type = ?, resource_id = ?, expires_at = ?, revoked_at = ?, metadata = ? WHERE id = ?', $this->table('grants')),
                [
                    $grant->principalId,
                    $grant->permission,
                    $grant->resourceType,
                    $grant->resourceId,
                    $grant->expiresAt,
                    $grant->revokedAt,
                    DBLayerJson::encode($grant->metadata),
                    $grant->id,
                ],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, principal_id, permission, resource_type, resource_id, expires_at, revoked_at, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $this->table('grants')),
            [
                $grant->id,
                $grant->principalId,
                $grant->permission,
                $grant->resourceType,
                $grant->resourceId,
                $grant->expiresAt,
                $grant->revokedAt,
                DBLayerJson::encode($grant->metadata),
            ],
        );
    }
}
