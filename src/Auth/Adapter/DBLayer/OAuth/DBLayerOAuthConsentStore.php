<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth;

use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerJson;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerStore;
use Infocyph\Foundation\Auth\OAuth\Consent\OAuthConsent;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthConsentStoreInterface;

final readonly class DBLayerOAuthConsentStore extends DBLayerStore implements OAuthConsentStoreInterface
{
    public function find(string $accountId, string $clientId, string $scopeFingerprint): ?OAuthConsent
    {
        return $this->firstMapped(
            sprintf(
                'SELECT * FROM %s WHERE account_id = ? AND client_id = ? AND scope_fingerprint = ?',
                $this->table('oauthConsents'),
            ),
            $this->mapConsent(...),
            [$accountId, $clientId, $scopeFingerprint],
        );
    }

    public function findActive(string $accountId, string $clientId, string $scopeFingerprint): ?OAuthConsent
    {
        $consent = $this->find($accountId, $clientId, $scopeFingerprint);

        return $consent instanceof OAuthConsent && $consent->revokedAt === null ? $consent : null;
    }

    public function revoke(string $accountId, string $clientId, int $revokedAt): int
    {
        return $this->connection()->execute(
            sprintf(
                'UPDATE %s SET revoked_at = ? WHERE account_id = ? AND client_id = ? AND revoked_at IS NULL',
                $this->table('oauthConsents'),
            ),
            [$revokedAt, $accountId, $clientId],
        )->rowCount();
    }

    public function save(OAuthConsent $consent): void
    {
        $this->upsertRecord('oauthConsents', 'id', [
            'id' => $consent->id,
            'account_id' => $consent->accountId,
            'client_id' => $consent->clientId,
            'scope_fingerprint' => $consent->scopeFingerprint,
            'scopes' => DBLayerJson::encodeList($consent->scopes),
            'audiences' => DBLayerJson::encodeList($consent->audiences),
            'granted_at' => $consent->grantedAt,
            'revoked_at' => $consent->revokedAt,
            'metadata' => DBLayerJson::encode($consent->metadata),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function mapConsent(array $row): OAuthConsent
    {
        return new OAuthConsent(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            clientId: $this->string($row['client_id'] ?? ''),
            scopeFingerprint: $this->string($row['scope_fingerprint'] ?? ''),
            scopes: DBLayerJson::decodeStringList($row['scopes'] ?? null),
            audiences: DBLayerJson::decodeStringList($row['audiences'] ?? null),
            grantedAt: $this->int($row['granted_at'] ?? 0),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
