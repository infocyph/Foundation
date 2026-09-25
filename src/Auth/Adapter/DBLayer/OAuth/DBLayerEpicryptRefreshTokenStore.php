<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenGrant;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenInspectionStatus;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenRecord;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenRotationStatus;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerJson;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;

final readonly class DBLayerEpicryptRefreshTokenStore extends DBLayerStore implements RefreshTokenStoreInterface
{
    private const int ROTATION_TRANSACTION_ATTEMPTS = 3;

    public function create(#[\SensitiveParameter] RefreshTokenRecord $record): bool
    {
        try {
            $this->insert($this->connection(), $record);

            return true;
        } catch (\Throwable $exception) {
            if ($this->findRow($this->connection(), $record->tokenId) !== null) {
                return false;
            }

            throw $exception;
        }
    }

    public function inspect(#[\SensitiveParameter] RefreshTokenRecord $record, int $now): RefreshTokenInspectionStatus
    {
        $row = $this->findRow($this->connection(), $record->tokenId);
        if ($row === null) {
            return RefreshTokenInspectionStatus::INVALID;
        }
        $stored = $this->mapRecord($row);
        if (!$stored->sameState($record)) {
            return RefreshTokenInspectionStatus::INVALID;
        }
        if ($this->intOrNull($row['revoked_at'] ?? null) !== null) {
            return RefreshTokenInspectionStatus::REVOKED;
        }
        if ($this->intOrNull($row['rotated_at'] ?? null) !== null) {
            return RefreshTokenInspectionStatus::CONSUMED;
        }
        if ($now >= $stored->grant->expiresAt || $now >= $stored->idleExpiresAt) {
            return RefreshTokenInspectionStatus::EXPIRED;
        }

        return RefreshTokenInspectionStatus::ACTIVE;
    }

    public function revokeAuthorization(string $authorizationId, int $revokedAt): int
    {
        $connection = $this->connection();
        $families = $connection->select(
            sprintf(
                'SELECT DISTINCT family_id FROM %s WHERE authorization_id = ? AND revoked_at IS NULL',
                $this->table('oauthRefreshTokens'),
            ),
            [$authorizationId],
        );
        if ($families === []) {
            return 0;
        }

        $connection->execute(
            sprintf(
                'UPDATE %s SET revoked_at = ? WHERE authorization_id = ? AND revoked_at IS NULL',
                $this->table('oauthRefreshTokens'),
            ),
            [$revokedAt, $authorizationId],
        );

        return count($families);
    }

    public function revokeFamily(string $tokenId, int $revokedAt): bool
    {
        $row = $this->findRow($this->connection(), $tokenId);
        if ($row === null) {
            return false;
        }
        $familyId = $this->string($row['family_id'] ?? '');
        $this->connection()->execute(
            sprintf(
                'UPDATE %s SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL',
                $this->table('oauthRefreshTokens'),
            ),
            [$revokedAt, $familyId],
        );

        return true;
    }

    public function rotate(
        #[\SensitiveParameter]
        RefreshTokenRecord $current,
        #[\SensitiveParameter]
        RefreshTokenRecord $replacement,
        string $clientId,
        ?string $dpopKeyThumbprint,
        int $now,
    ): RefreshTokenRotationStatus {
        $result = $this->connection()->transaction(
            fn(Connection $transaction): RefreshTokenRotationStatus => $this->rotateTransaction(
                $transaction,
                $current,
                $replacement,
                $clientId,
                $dpopKeyThumbprint,
                $now,
            ),
            self::ROTATION_TRANSACTION_ATTEMPTS,
        );

        return $result instanceof RefreshTokenRotationStatus
            ? $result
            : throw new \RuntimeException('OAuth refresh-token transaction returned an invalid status.');
    }

    /** @return array<string,mixed>|null */
    private function findRow(Connection $connection, string $tokenId): ?array
    {
        $row = $connection->select(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('oauthRefreshTokens')),
            [$tokenId],
        )[0] ?? null;

        return is_array($row) ? $row : null;
    }

    private function insert(Connection $connection, RefreshTokenRecord $record): void
    {
        $grant = $record->grant;
        $connection->table($this->table('oauthRefreshTokens'))->insert([
            'id' => $record->tokenId,
            'token_hash' => hash('sha256', "epicrypt-refresh\0" . $record->tokenId),
            'family_id' => $record->familyId,
            'client_id' => $grant->clientId,
            'account_id' => $grant->subject,
            'device_id' => null,
            'authorization_id' => $grant->authorizationId,
            'scopes' => DBLayerJson::encodeList($grant->scopes),
            'audiences' => DBLayerJson::encodeList($grant->audiences),
            'issued_at' => $record->issuedAt,
            'expires_at' => $grant->expiresAt,
            'idle_expires_at' => $record->idleExpiresAt,
            'dpop_jkt' => $grant->dpopKeyThumbprint,
            'rotated_at' => null,
            'revoked_at' => null,
            'metadata' => null,
        ]);
    }

    /** @param array<string,mixed> $row */
    private function mapRecord(array $row): RefreshTokenRecord
    {
        $expiresAt = $this->int($row['expires_at'] ?? 0);
        $idleExpiresAt = $this->intOrNull($row['idle_expires_at'] ?? null);
        $idleExpiresAt ??= $expiresAt;

        return new RefreshTokenRecord(
            tokenId: $this->string($row['id'] ?? ''),
            familyId: $this->string($row['family_id'] ?? ''),
            grant: new RefreshTokenGrant(
                authorizationId: $this->string($row['authorization_id'] ?? ''),
                subject: $this->string($row['account_id'] ?? ''),
                clientId: $this->string($row['client_id'] ?? ''),
                audiences: DBLayerJson::decodeStringList($row['audiences'] ?? null),
                scopes: DBLayerJson::decodeStringList($row['scopes'] ?? null),
                expiresAt: $expiresAt,
                dpopKeyThumbprint: $this->stringOrNull($row['dpop_jkt'] ?? null),
            ),
            issuedAt: $this->int($row['issued_at'] ?? 0),
            idleExpiresAt: $idleExpiresAt,
        );
    }

    private function revokeFamilyUsing(Connection $connection, string $familyId, int $revokedAt): void
    {
        $connection->execute(
            sprintf(
                'UPDATE %s SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL',
                $this->table('oauthRefreshTokens'),
            ),
            [$revokedAt, $familyId],
        );
    }

    private function rotateTransaction(
        Connection $transaction,
        RefreshTokenRecord $current,
        RefreshTokenRecord $replacement,
        string $clientId,
        ?string $dpopKeyThumbprint,
        int $now,
    ): RefreshTokenRotationStatus {
        $row = $this->findRow($transaction, $current->tokenId);
        if ($row === null) {
            return RefreshTokenRotationStatus::INVALID;
        }

        $stored = $this->mapRecord($row);
        if (!$stored->sameState($current)) {
            return RefreshTokenRotationStatus::INVALID;
        }
        if ($this->intOrNull($row['revoked_at'] ?? null) !== null) {
            return RefreshTokenRotationStatus::REVOKED;
        }
        if ($this->intOrNull($row['rotated_at'] ?? null) !== null) {
            $this->revokeFamilyUsing($transaction, $stored->familyId, $now);

            return RefreshTokenRotationStatus::REUSED;
        }
        if (!hash_equals($stored->grant->clientId, $clientId)) {
            return RefreshTokenRotationStatus::CLIENT_MISMATCH;
        }
        if ($stored->grant->dpopKeyThumbprint !== $dpopKeyThumbprint) {
            return RefreshTokenRotationStatus::SENDER_MISMATCH;
        }
        if ($now >= $stored->grant->expiresAt || $now >= $stored->idleExpiresAt) {
            return RefreshTokenRotationStatus::EXPIRED;
        }
        if ($this->findRow($transaction, $replacement->tokenId) !== null) {
            return RefreshTokenRotationStatus::CONFLICT;
        }

        $affected = $transaction->execute(
            sprintf(
                'UPDATE %s SET rotated_at = ? WHERE id = ? AND rotated_at IS NULL AND revoked_at IS NULL',
                $this->table('oauthRefreshTokens'),
            ),
            [$now, $current->tokenId],
        )->rowCount();
        if ($affected !== 1) {
            return RefreshTokenRotationStatus::CONFLICT;
        }

        $this->insert($transaction, $replacement);

        return RefreshTokenRotationStatus::ROTATED;
    }
}
