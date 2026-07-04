<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;

final readonly class DBLayerPasskeyCredentialStore extends ClockedDBLayerStore implements PasskeyCredentialStoreInterface
{
    public function findByCredentialId(string $credentialId): ?PasskeyCredential
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE credential_id = ? AND revoked_at IS NULL', $this->table('passkeyCredentials')),
            $this->mapCredential(...),
            [$credentialId],
        );
    }

    public function findForAccount(string $accountId): array
    {
        return $this->allMapped(
            sprintf('SELECT * FROM %s WHERE account_id = ? AND revoked_at IS NULL', $this->table('passkeyCredentials')),
            $this->mapCredential(...),
            [$accountId],
        );
    }

    public function revoke(string $credentialId): void
    {
        $this->updateWhere('passkeyCredentials', ['revoked_at' => $this->now()], '(credential_id = ? OR id = ?) AND revoked_at IS NULL', [$credentialId, $credentialId]);
    }

    public function save(PasskeyCredential $credential): void
    {
        $this->upsertRecord('passkeyCredentials', 'id', [
            'id' => $credential->id,
            'account_id' => $credential->accountId,
            'credential_id' => $credential->credentialId,
            'public_key' => $credential->publicKey,
            'sign_count' => $credential->signCount,
            'transports' => DBLayerJson::encodeList($credential->transports),
            'created_at' => $credential->createdAt,
            'last_used_at' => $credential->lastUsedAt,
            'revoked_at' => $credential->revokedAt,
            'metadata' => DBLayerJson::encode($credential->metadata),
        ]);
    }

    public function updateUsage(string $credentialId, int $signCount, int $usedAt): void
    {
        $this->updateWhere('passkeyCredentials', ['sign_count' => $signCount, 'last_used_at' => $usedAt], 'credential_id = ? AND revoked_at IS NULL', [$credentialId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapCredential(array $row): PasskeyCredential
    {
        return new PasskeyCredential(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            credentialId: $this->string($row['credential_id'] ?? ''),
            publicKey: $this->string($row['public_key'] ?? ''),
            signCount: $this->int($row['sign_count'] ?? 0),
            transports: DBLayerJson::decodeStringList($row['transports'] ?? null),
            createdAt: $this->int($row['created_at'] ?? 0),
            lastUsedAt: $this->intOrNull($row['last_used_at'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
