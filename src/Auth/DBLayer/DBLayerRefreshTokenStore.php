<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

use Infocyph\AuthLayer\Authentication\TokenAuth\RefreshTokenRecord;
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Contract\Storage\RefreshTokenStoreInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerRefreshTokenStore extends DBLayerStore implements RefreshTokenStoreInterface
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        private ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

    public function find(string $tokenId): ?RefreshTokenRecord
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('refreshTokens')),
            [$tokenId],
        );

        return $row === null ? null : $this->mapRecord($row);
    }

    public function revokeFamily(string $familyId): void
    {
        $this->execute(
            sprintf('UPDATE %s SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL', $this->table('refreshTokens')),
            [$this->clock->now(), $familyId],
        );
    }

    public function rotate(string $tokenId, RefreshTokenRecord $replacement): void
    {
        $this->execute(
            sprintf('UPDATE %s SET rotated_at = ? WHERE id = ?', $this->table('refreshTokens')),
            [$this->clock->now(), $tokenId],
        );

        $this->save($replacement);
    }

    public function save(RefreshTokenRecord $record): void
    {
        if ($this->find($record->id) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET account_id = ?, token_hash = ?, family_id = ?, client_id = ?, device_id = ?, issued_at = ?, expires_at = ?, rotated_at = ?, revoked_at = ?, metadata = ? WHERE id = ?', $this->table('refreshTokens')),
                [
                    $record->accountId,
                    $record->tokenHash,
                    $record->familyId,
                    $record->clientId,
                    $record->deviceId,
                    $record->issuedAt,
                    $record->expiresAt,
                    $record->rotatedAt,
                    $record->revokedAt,
                    DBLayerJson::encode($record->metadata),
                    $record->id,
                ],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, account_id, token_hash, family_id, client_id, device_id, issued_at, expires_at, rotated_at, revoked_at, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $this->table('refreshTokens')),
            [
                $record->id,
                $record->accountId,
                $record->tokenHash,
                $record->familyId,
                $record->clientId,
                $record->deviceId,
                $record->issuedAt,
                $record->expiresAt,
                $record->rotatedAt,
                $record->revokedAt,
                DBLayerJson::encode($record->metadata),
            ],
        );
    }

    public function wasFamilyRevoked(string $familyId): bool
    {
        return $this->first(
            sprintf('SELECT id FROM %s WHERE family_id = ? AND revoked_at IS NOT NULL', $this->table('refreshTokens')),
            [$familyId],
        ) !== null;
    }

    private function mapRecord(array $row): RefreshTokenRecord
    {
        return new RefreshTokenRecord(
            tokenHash: $this->string($row['token_hash'] ?? ''),
            clientId: $this->stringOrNull($row['client_id'] ?? null),
            deviceId: $this->stringOrNull($row['device_id'] ?? null),
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            familyId: $this->string($row['family_id'] ?? ''),
            issuedAt: $this->int($row['issued_at'] ?? 0),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            rotatedAt: $this->intOrNull($row['rotated_at'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
