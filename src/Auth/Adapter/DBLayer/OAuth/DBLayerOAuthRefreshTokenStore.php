<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerJson;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthRefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshRotationResult;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshRotationStatus;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenRecord;

final readonly class DBLayerOAuthRefreshTokenStore extends DBLayerStore implements OAuthRefreshTokenStoreInterface
{
    public function findByHash(string $tokenHash): ?OAuthRefreshTokenRecord
    {
        return $this->findRecord($this->connection(), $tokenHash);
    }

    public function revokeFamily(string $familyId, int $revokedAt): void
    {
        $table = $this->table('oauthRefreshTokens');
        $this->connection()->execute(
            sprintf('UPDATE %s SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL', $table),
            [$revokedAt, $familyId],
        );
    }

    public function rotate(
        string $tokenHash,
        OAuthRefreshTokenRecord $replacement,
        int $rotatedAt,
    ): OAuthRefreshRotationResult {
        $connection = $this->connection();

        return $connection->transaction(function (Connection $transaction) use (
            $tokenHash,
            $replacement,
            $rotatedAt,
        ): OAuthRefreshRotationResult {
            $current = $this->findRecord($transaction, $tokenHash);
            if (!$current instanceof OAuthRefreshTokenRecord) {
                return new OAuthRefreshRotationResult(OAuthRefreshRotationStatus::Missing);
            }
            if ($current->revokedAt !== null) {
                return new OAuthRefreshRotationResult(OAuthRefreshRotationStatus::Revoked, $current);
            }
            if ($current->rotatedAt !== null) {
                return new OAuthRefreshRotationResult(OAuthRefreshRotationStatus::Reused, $current);
            }

            $table = $this->table('oauthRefreshTokens');
            $affected = $transaction->execute(
                sprintf(
                    'UPDATE %s SET rotated_at = ? WHERE token_hash = ? AND rotated_at IS NULL AND revoked_at IS NULL',
                    $table,
                ),
                [$rotatedAt, $tokenHash],
            )->rowCount();

            if ($affected !== 1) {
                $latest = $this->findRecord($transaction, $tokenHash);

                return new OAuthRefreshRotationResult(
                    $latest?->revokedAt !== null
                        ? OAuthRefreshRotationStatus::Revoked
                        : OAuthRefreshRotationStatus::Reused,
                    $latest,
                );
            }

            $this->insert($transaction, $replacement);

            return new OAuthRefreshRotationResult(OAuthRefreshRotationStatus::Rotated, $replacement);
        });
    }

    public function save(OAuthRefreshTokenRecord $record): void
    {
        $this->insert($this->connection(), $record);
    }

    private function findRecord(Connection $connection, string $tokenHash): ?OAuthRefreshTokenRecord
    {
        $rows = $connection->select(
            sprintf('SELECT * FROM %s WHERE token_hash = ?', $this->table('oauthRefreshTokens')),
            [$tokenHash],
        );
        $row = $rows[0] ?? null;

        return is_array($row) ? $this->mapRecord($row) : null;
    }

    private function insert(Connection $connection, OAuthRefreshTokenRecord $record): void
    {
        $connection->table($this->table('oauthRefreshTokens'))->insert([
            'id' => $record->id,
            'token_hash' => $record->tokenHash,
            'family_id' => $record->familyId,
            'client_id' => $record->clientId,
            'account_id' => $record->accountId,
            'device_id' => $record->deviceId,
            'authorization_id' => $record->authorizationId,
            'scopes' => DBLayerJson::encodeList($record->scopes),
            'audiences' => DBLayerJson::encodeList($record->audiences),
            'issued_at' => $record->issuedAt,
            'expires_at' => $record->expiresAt,
            'rotated_at' => $record->rotatedAt,
            'revoked_at' => $record->revokedAt,
            'metadata' => DBLayerJson::encode($record->metadata),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function mapRecord(array $row): OAuthRefreshTokenRecord
    {
        return new OAuthRefreshTokenRecord(
            id: $this->string($row['id'] ?? ''),
            tokenHash: $this->string($row['token_hash'] ?? ''),
            familyId: $this->string($row['family_id'] ?? ''),
            clientId: $this->string($row['client_id'] ?? ''),
            accountId: $this->stringOrNull($row['account_id'] ?? null),
            deviceId: $this->stringOrNull($row['device_id'] ?? null),
            authorizationId: $this->string($row['authorization_id'] ?? ''),
            scopes: DBLayerJson::decodeStringList($row['scopes'] ?? null),
            audiences: DBLayerJson::decodeStringList($row['audiences'] ?? null),
            issuedAt: $this->int($row['issued_at'] ?? 0),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            rotatedAt: $this->intOrNull($row['rotated_at'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
