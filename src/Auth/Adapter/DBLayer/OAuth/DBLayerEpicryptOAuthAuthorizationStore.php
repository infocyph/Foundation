<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationRecord;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationStoreInterface;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerJson;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;

final readonly class DBLayerEpicryptOAuthAuthorizationStore extends DBLayerStore implements OAuthAuthorizationStoreInterface
{
    public function create(OAuthAuthorizationRecord $record): bool
    {
        try {
            $this->insertRecord('oauthAuthorizations', [
                'id' => $record->authorizationId,
                'client_id' => $record->clientId,
                'account_id' => $record->subject,
                'scopes' => DBLayerJson::encodeList($record->scopes),
                'audiences' => DBLayerJson::encodeList($record->audiences),
                'created_at' => $record->authorizedAt,
                'expires_at' => $record->expiresAt,
                'revoked_at' => $record->revokedAt,
                'metadata' => null,
            ]);

            return true;
        } catch (\Throwable $exception) {
            if ($this->exists(
                sprintf('SELECT id FROM %s WHERE id = ?', $this->table('oauthAuthorizations')),
                [$record->authorizationId],
            )) {
                return false;
            }

            throw $exception;
        }
    }

    public function find(string $authorizationId): ?OAuthAuthorizationRecord
    {
        return $this->findUsing($this->connection(), $authorizationId);
    }

    public function revoke(string $authorizationId, int $revokedAt): ?OAuthAuthorizationRecord
    {
        $result = $this->connection()->transaction(function (Connection $transaction) use ($authorizationId, $revokedAt): ?OAuthAuthorizationRecord {
            $transaction->execute(
                sprintf(
                    'UPDATE %s SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
                    $this->table('oauthAuthorizations'),
                ),
                [$revokedAt, $authorizationId],
            );

            return $this->findUsing($transaction, $authorizationId);
        });

        return $result instanceof OAuthAuthorizationRecord ? $result : null;
    }

    private function findUsing(Connection $connection, string $authorizationId): ?OAuthAuthorizationRecord
    {
        $row = $connection->select(
            sprintf(
                'SELECT id, client_id, account_id, scopes, audiences, created_at, expires_at, revoked_at FROM %s WHERE id = ?',
                $this->table('oauthAuthorizations'),
            ),
            [$authorizationId],
        )[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        $subject = $this->stringOrNull($row['account_id'] ?? null);
        $expiresAt = $this->intOrNull($row['expires_at'] ?? null);
        if ($subject === null || $expiresAt === null) {
            return null;
        }

        return new OAuthAuthorizationRecord(
            authorizationId: $this->string($row['id'] ?? ''),
            subject: $subject,
            clientId: $this->string($row['client_id'] ?? ''),
            scopes: DBLayerJson::decodeStringList($row['scopes'] ?? null),
            audiences: DBLayerJson::decodeStringList($row['audiences'] ?? null),
            authorizedAt: $this->int($row['created_at'] ?? 0),
            expiresAt: $expiresAt,
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
        );
    }
}
