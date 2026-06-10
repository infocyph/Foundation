<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

use Infocyph\AuthLayer\Authentication\RememberMe\RememberTokenRecord;
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Contract\Storage\RememberTokenStoreInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerRememberTokenStore extends DBLayerStore implements RememberTokenStoreInterface
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        private ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

    public function find(string $recordId): ?RememberTokenRecord
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('rememberTokens')),
            [$recordId],
        );

        return $row === null ? null : $this->mapRecord($row);
    }

    public function findBySelector(string $selector): ?RememberTokenRecord
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE selector = ?', $this->table('rememberTokens')),
            [$selector],
        );

        return $row === null ? null : $this->mapRecord($row);
    }

    public function markUsed(string $recordId, int $usedAt): void
    {
        $this->execute(
            sprintf('UPDATE %s SET last_used_at = ? WHERE id = ? AND revoked_at IS NULL', $this->table('rememberTokens')),
            [$usedAt, $recordId],
        );
    }

    public function revokeFamily(string $familyId): void
    {
        $this->execute(
            sprintf('UPDATE %s SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL', $this->table('rememberTokens')),
            [$this->clock->now(), $familyId],
        );
    }

    public function rotate(string $recordId, RememberTokenRecord $replacement): void
    {
        $this->execute(
            sprintf('UPDATE %s SET rotated_at = ? WHERE id = ?', $this->table('rememberTokens')),
            [$this->clock->now(), $recordId],
        );

        $this->save($replacement);
    }

    public function save(RememberTokenRecord $record): void
    {
        if ($this->find($record->id) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET account_id = ?, device_id = ?, selector = ?, verifier_hash = ?, family_id = ?, issued_at = ?, expires_at = ?, last_used_at = ?, rotated_at = ?, revoked_at = ?, metadata = ? WHERE id = ?', $this->table('rememberTokens')),
                [
                    $record->accountId,
                    $record->deviceId,
                    $record->selector,
                    $record->verifierHash,
                    $record->familyId,
                    $record->issuedAt,
                    $record->expiresAt,
                    $record->lastUsedAt,
                    $record->rotatedAt,
                    $record->revokedAt,
                    DBLayerJson::encode($record->metadata),
                    $record->id,
                ],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, account_id, device_id, selector, verifier_hash, family_id, issued_at, expires_at, last_used_at, rotated_at, revoked_at, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $this->table('rememberTokens')),
            [
                $record->id,
                $record->accountId,
                $record->deviceId,
                $record->selector,
                $record->verifierHash,
                $record->familyId,
                $record->issuedAt,
                $record->expiresAt,
                $record->lastUsedAt,
                $record->rotatedAt,
                $record->revokedAt,
                DBLayerJson::encode($record->metadata),
            ],
        );
    }

    public function wasFamilyRevoked(string $familyId): bool
    {
        return $this->first(
            sprintf('SELECT id FROM %s WHERE family_id = ? AND revoked_at IS NOT NULL', $this->table('rememberTokens')),
            [$familyId],
        ) !== null;
    }

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
