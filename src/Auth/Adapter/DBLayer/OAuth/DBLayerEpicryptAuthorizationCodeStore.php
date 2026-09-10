<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Epicrypt\Auth\OAuth\AuthorizationCodeConsumeStatus;
use Infocyph\Epicrypt\Auth\OAuth\AuthorizationCodeRecord;
use Infocyph\Epicrypt\Auth\OAuth\AuthorizationCodeStoreInterface;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;

final readonly class DBLayerEpicryptAuthorizationCodeStore extends DBLayerStore implements AuthorizationCodeStoreInterface
{
    public function consume(
        #[\SensitiveParameter]
        AuthorizationCodeRecord $record,
        int $now,
    ): AuthorizationCodeConsumeStatus {
        $result = $this->connection()->transaction(function (Connection $transaction) use ($record, $now): AuthorizationCodeConsumeStatus {
            $affected = $transaction->execute(
                sprintf(
                    'UPDATE %s SET consumed_at = ? WHERE id = ? AND authorization_id = ? AND expires_at = ? AND state_digest = ? AND consumed_at IS NULL AND expires_at > ?',
                    $this->table('oauthAuthorizationCodeStates'),
                ),
                [
                    $now,
                    $record->codeId,
                    $record->authorizationId,
                    $record->expiresAt,
                    $record->stateDigest,
                    $now,
                ],
            )->rowCount();

            if ($affected === 1) {
                return AuthorizationCodeConsumeStatus::CONSUMED;
            }

            $row = $transaction->select(
                sprintf(
                    'SELECT id, authorization_id, expires_at, state_digest, consumed_at FROM %s WHERE id = ?',
                    $this->table('oauthAuthorizationCodeStates'),
                ),
                [$record->codeId],
            )[0] ?? null;
            if (!is_array($row)) {
                return AuthorizationCodeConsumeStatus::INVALID;
            }

            $stored = new AuthorizationCodeRecord(
                codeId: $this->string($row['id'] ?? ''),
                authorizationId: $this->string($row['authorization_id'] ?? ''),
                expiresAt: $this->int($row['expires_at'] ?? 0),
                stateDigest: $this->string($row['state_digest'] ?? ''),
            );
            if (!$stored->sameState($record)) {
                return AuthorizationCodeConsumeStatus::INVALID;
            }
            if ($this->intOrNull($row['consumed_at'] ?? null) !== null) {
                return AuthorizationCodeConsumeStatus::REPLAYED;
            }
            if ($stored->expiresAt <= $now) {
                return AuthorizationCodeConsumeStatus::EXPIRED;
            }

            return AuthorizationCodeConsumeStatus::INVALID;
        });

        if (!$result instanceof AuthorizationCodeConsumeStatus) {
            throw new \RuntimeException('OAuth authorization-code transaction returned an invalid result.');
        }

        return $result;
    }

    public function create(#[\SensitiveParameter] AuthorizationCodeRecord $record): bool
    {
        try {
            $this->insertRecord('oauthAuthorizationCodeStates', [
                'id' => $record->codeId,
                'authorization_id' => $record->authorizationId,
                'expires_at' => $record->expiresAt,
                'state_digest' => $record->stateDigest,
                'consumed_at' => null,
            ]);

            return true;
        } catch (\Throwable $exception) {
            if ($this->exists(
                sprintf(
                    'SELECT id FROM %s WHERE id = ?',
                    $this->table('oauthAuthorizationCodeStates'),
                ),
                [$record->codeId],
            )) {
                return false;
            }

            throw $exception;
        }
    }
}
