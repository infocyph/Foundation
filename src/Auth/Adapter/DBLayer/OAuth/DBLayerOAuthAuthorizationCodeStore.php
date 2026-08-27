<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerJson;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCode;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCodeConsumeResult;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCodeConsumeStatus;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthAuthorizationCodeStoreInterface;

final readonly class DBLayerOAuthAuthorizationCodeStore extends DBLayerStore implements OAuthAuthorizationCodeStoreInterface
{
    public function consume(
        string $codeHash,
        string $clientId,
        string $redirectUriHash,
        string $pkceChallenge,
        int $now,
    ): OAuthAuthorizationCodeConsumeResult {
        $connection = $this->connection();
        $result = $connection->transaction(function (Connection $transaction) use (
            $codeHash,
            $clientId,
            $redirectUriHash,
            $pkceChallenge,
            $now,
        ): OAuthAuthorizationCodeConsumeResult {
            $affected = $transaction->execute(
                sprintf(
                    'UPDATE %s SET consumed_at = ? WHERE code_hash = ? AND client_id = ? AND redirect_uri_hash = ? AND pkce_challenge = ? AND consumed_at IS NULL AND expires_at > ?',
                    $this->table('oauthAuthorizationCodes'),
                ),
                [$now, $codeHash, $clientId, $redirectUriHash, $pkceChallenge, $now],
            )->rowCount();

            $latest = $this->find($transaction, $codeHash);
            if ($affected === 1 && $latest instanceof OAuthAuthorizationCode) {
                return new OAuthAuthorizationCodeConsumeResult(
                    OAuthAuthorizationCodeConsumeStatus::Consumed,
                    $latest,
                );
            }

            $latestStatus = $this->preconditionStatus($latest, $clientId, $redirectUriHash, $pkceChallenge, $now)
                ?? OAuthAuthorizationCodeConsumeStatus::Reused;

            return new OAuthAuthorizationCodeConsumeResult($latestStatus, $latest);
        });
        if (!$result instanceof OAuthAuthorizationCodeConsumeResult) {
            throw new \RuntimeException('OAuth authorization-code transaction returned an invalid result.');
        }

        return $result;
    }

    public function save(OAuthAuthorizationCode $code): void
    {
        $this->insertRecord('oauthAuthorizationCodes', [
            'id' => $code->id,
            'code_hash' => $code->codeHash,
            'client_id' => $code->clientId,
            'account_id' => $code->accountId,
            'authorization_id' => $code->authorizationId,
            'redirect_uri_hash' => $code->redirectUriHash,
            'pkce_challenge' => $code->pkceChallenge,
            'pkce_method' => 'S256',
            'scopes' => DBLayerJson::encodeList($code->scopes),
            'audiences' => DBLayerJson::encodeList($code->audiences),
            'issued_at' => $code->issuedAt,
            'expires_at' => $code->expiresAt,
            'consumed_at' => $code->consumedAt,
            'metadata' => DBLayerJson::encode($code->metadata),
        ]);
    }

    private function find(Connection $connection, string $codeHash): ?OAuthAuthorizationCode
    {
        $rows = $connection->select(
            sprintf('SELECT * FROM %s WHERE code_hash = ?', $this->table('oauthAuthorizationCodes')),
            [$codeHash],
        );
        $row = $rows[0] ?? null;

        return is_array($row) ? $this->mapCode($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function mapCode(array $row): OAuthAuthorizationCode
    {
        if ($this->string($row['pkce_method'] ?? '') !== 'S256') {
            throw new \RuntimeException('Stored OAuth authorization code PKCE policy is invalid.');
        }

        return new OAuthAuthorizationCode(
            id: $this->string($row['id'] ?? ''),
            codeHash: $this->string($row['code_hash'] ?? ''),
            clientId: $this->string($row['client_id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            authorizationId: $this->string($row['authorization_id'] ?? ''),
            redirectUriHash: $this->string($row['redirect_uri_hash'] ?? ''),
            pkceChallenge: $this->string($row['pkce_challenge'] ?? ''),
            scopes: DBLayerJson::decodeStringList($row['scopes'] ?? null),
            audiences: DBLayerJson::decodeStringList($row['audiences'] ?? null),
            issuedAt: $this->int($row['issued_at'] ?? 0),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            consumedAt: $this->intOrNull($row['consumed_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }

    private function preconditionStatus(
        ?OAuthAuthorizationCode $code,
        string $clientId,
        string $redirectUriHash,
        string $pkceChallenge,
        int $now,
    ): ?OAuthAuthorizationCodeConsumeStatus {
        if (!$code instanceof OAuthAuthorizationCode) {
            return OAuthAuthorizationCodeConsumeStatus::Missing;
        }
        if (
            !hash_equals($code->clientId, $clientId)
            || !hash_equals($code->redirectUriHash, $redirectUriHash)
            || !hash_equals($code->pkceChallenge, $pkceChallenge)
        ) {
            return OAuthAuthorizationCodeConsumeStatus::Mismatched;
        }
        if ($code->expiresAt <= $now) {
            return OAuthAuthorizationCodeConsumeStatus::Expired;
        }
        if ($code->consumedAt !== null) {
            return OAuthAuthorizationCodeConsumeStatus::Reused;
        }

        return null;
    }
}
