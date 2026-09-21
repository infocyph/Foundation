<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAccessTokenStatusRecord;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAccessTokenStatusStoreInterface;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;

final readonly class DBLayerEpicryptAccessTokenStatusStore extends DBLayerStore implements OAuthAccessTokenStatusStoreInterface
{
    public function create(OAuthAccessTokenStatusRecord $record): bool
    {
        $id = $this->id($record->issuer, $record->tokenId);

        try {
            $this->insertRecord('oauthAccessStatuses', [
                'id' => $id,
                'issuer' => $record->issuer,
                'token_id' => $record->tokenId,
                'subject' => $record->subject,
                'client_id' => $record->clientId,
                'authorization_id' => $record->authorizationId,
                'expires_at' => $record->expiresAt,
                'revoked_at' => $record->revokedAt,
            ]);

            return true;
        } catch (\Throwable $exception) {
            if ($this->find($record->issuer, $record->tokenId) instanceof OAuthAccessTokenStatusRecord) {
                return false;
            }

            throw $exception;
        }
    }

    public function find(string $issuer, string $tokenId): ?OAuthAccessTokenStatusRecord
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('oauthAccessStatuses')),
            [$this->id($issuer, $tokenId)],
        );

        return is_array($row) ? $this->map($row, $issuer, $tokenId) : null;
    }

    public function revoke(string $issuer, string $tokenId, int $revokedAt): ?OAuthAccessTokenStatusRecord
    {
        $result = $this->connection()->transaction(function (Connection $transaction) use ($issuer, $tokenId, $revokedAt): ?OAuthAccessTokenStatusRecord {
            $id = $this->id($issuer, $tokenId);
            $transaction->execute(
                sprintf(
                    'UPDATE %s SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
                    $this->table('oauthAccessStatuses'),
                ),
                [$revokedAt, $id],
            );
            $row = $transaction->select(
                sprintf('SELECT * FROM %s WHERE id = ?', $this->table('oauthAccessStatuses')),
                [$id],
            )[0] ?? null;

            return is_array($row) ? $this->map($row, $issuer, $tokenId) : null;
        });

        return $result instanceof OAuthAccessTokenStatusRecord ? $result : null;
    }

    private function id(string $issuer, string $tokenId): string
    {
        return hash('sha256', "foundation.oauth.access-status\0" . $issuer . "\0" . $tokenId);
    }

    /** @param array<string,mixed> $row */
    private function map(array $row, string $issuer, string $tokenId): ?OAuthAccessTokenStatusRecord
    {
        if (!hash_equals($issuer, $this->string($row['issuer'] ?? ''))
            || !hash_equals($tokenId, $this->string($row['token_id'] ?? ''))) {
            return null;
        }

        return new OAuthAccessTokenStatusRecord(
            issuer: $issuer,
            tokenId: $tokenId,
            subject: $this->string($row['subject'] ?? ''),
            clientId: $this->string($row['client_id'] ?? ''),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            authorizationId: $this->stringOrNull($row['authorization_id'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
        );
    }
}
