<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenRecord;
use Infocyph\Foundation\Auth\Contract\Storage\RememberTokenStoreInterface;

final readonly class DBLayerRememberTokenStore extends DBLayerFamilyTokenStore implements RememberTokenStoreInterface
{
    public function find(string $recordId): ?RememberTokenRecord
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('rememberTokens')),
            $this->mapRecord(...),
            [$recordId],
        );
    }

    public function findBySelector(string $selector): ?RememberTokenRecord
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE selector = ?', $this->table('rememberTokens')),
            $this->mapRecord(...),
            [$selector],
        );
    }

    public function markUsed(string $recordId, int $usedAt): void
    {
        $this->updateWhere('rememberTokens', ['last_used_at' => $usedAt], 'id = ? AND revoked_at IS NULL', [$recordId]);
    }

    public function revokeFamily(string $familyId): void
    {
        $this->revokeTokenFamily('rememberTokens', $familyId);
    }

    public function rotate(string $recordId, RememberTokenRecord $replacement): void
    {
        $this->markTokenRotated('rememberTokens', $recordId);

        $this->save($replacement);
    }

    public function save(RememberTokenRecord $record): void
    {
        $this->upsertRecord('rememberTokens', 'id', [
            'id' => $record->id,
            'account_id' => $record->accountId,
            'device_id' => $record->deviceId,
            'selector' => $record->selector,
            'verifier_hash' => $record->verifierHash,
            'family_id' => $record->familyId,
            'issued_at' => $record->issuedAt,
            'expires_at' => $record->expiresAt,
            'last_used_at' => $record->lastUsedAt,
            'rotated_at' => $record->rotatedAt,
            'revoked_at' => $record->revokedAt,
            'metadata' => DBLayerJson::encode($record->metadata),
        ]);
    }

    public function wasFamilyRevoked(string $familyId): bool
    {
        return $this->tokenFamilyWasRevoked('rememberTokens', $familyId);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRecord(array $row): RememberTokenRecord
    {
        return new RememberTokenRecord(
            deviceId: $this->string($row['device_id'] ?? ''),
            selector: $this->string($row['selector'] ?? ''),
            verifierHash: $this->string($row['verifier_hash'] ?? ''),
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            familyId: $this->string($row['family_id'] ?? ''),
            issuedAt: $this->int($row['issued_at'] ?? 0),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            lastUsedAt: $this->intOrNull($row['last_used_at'] ?? null),
            rotatedAt: $this->intOrNull($row['rotated_at'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
