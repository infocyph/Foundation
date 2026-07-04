<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenRecord;
use Infocyph\Foundation\Auth\Contract\Storage\RefreshTokenStoreInterface;

final readonly class DBLayerRefreshTokenStore extends ClockedDBLayerStore implements RefreshTokenStoreInterface
{
    public function find(string $tokenId): ?RefreshTokenRecord
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('refreshTokens')),
            $this->mapRecord(...),
            [$tokenId],
        );
    }

    public function revokeFamily(string $familyId): void
    {
        $this->updateWhere('refreshTokens', ['revoked_at' => $this->now()], 'family_id = ? AND revoked_at IS NULL', [$familyId]);
    }

    public function rotate(string $tokenId, RefreshTokenRecord $replacement): void
    {
        $this->updateWhere('refreshTokens', ['rotated_at' => $this->now()], 'id = ?', [$tokenId]);

        $this->save($replacement);
    }

    public function save(RefreshTokenRecord $record): void
    {
        $this->upsertRecord('refreshTokens', 'id', [
            'id' => $record->id,
            'account_id' => $record->accountId,
            'token_hash' => $record->tokenHash,
            'family_id' => $record->familyId,
            'client_id' => $record->clientId,
            'device_id' => $record->deviceId,
            'issued_at' => $record->issuedAt,
            'expires_at' => $record->expiresAt,
            'rotated_at' => $record->rotatedAt,
            'revoked_at' => $record->revokedAt,
            'metadata' => DBLayerJson::encode($record->metadata),
        ]);
    }

    public function wasFamilyRevoked(string $familyId): bool
    {
        return $this->first(
            sprintf('SELECT id FROM %s WHERE family_id = ? AND revoked_at IS NOT NULL', $this->table('refreshTokens')),
            [$familyId],
        ) !== null;
    }

    /**
     * @param array<string, mixed> $row
     */
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
