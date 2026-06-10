<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Passkey\PasskeyCredential;
use Infocyph\AuthLayer\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerPasskeyCredentialStore extends DBLayerStore implements PasskeyCredentialStoreInterface
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        private ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

    public function findByCredentialId(string $credentialId): ?PasskeyCredential
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE credential_id = ? AND revoked_at IS NULL', $this->table('passkeyCredentials')),
            [$credentialId],
        );

        return $row === null ? null : $this->mapCredential($row);
    }

    public function findForAccount(string $accountId): array
    {
        return array_map(
            fn(array $row): PasskeyCredential => $this->mapCredential($row),
            $this->all(
                sprintf('SELECT * FROM %s WHERE account_id = ? AND revoked_at IS NULL', $this->table('passkeyCredentials')),
                [$accountId],
            ),
        );
    }

    public function revoke(string $credentialId): void
    {
        $this->execute(
            sprintf('UPDATE %s SET revoked_at = ? WHERE (credential_id = ? OR id = ?) AND revoked_at IS NULL', $this->table('passkeyCredentials')),
            [$this->clock->now(), $credentialId, $credentialId],
        );
    }

    public function save(PasskeyCredential $credential): void
    {
        if ($this->first(
            sprintf('SELECT id FROM %s WHERE id = ?', $this->table('passkeyCredentials')),
            [$credential->id],
        ) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET account_id = ?, credential_id = ?, public_key = ?, sign_count = ?, transports = ?, created_at = ?, last_used_at = ?, revoked_at = ?, metadata = ? WHERE id = ?', $this->table('passkeyCredentials')),
                [
                    $credential->accountId,
                    $credential->credentialId,
                    $credential->publicKey,
                    $credential->signCount,
                    DBLayerJson::encode($credential->transports),
                    $credential->createdAt,
                    $credential->lastUsedAt,
                    $credential->revokedAt,
                    DBLayerJson::encode($credential->metadata),
                    $credential->id,
                ],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, account_id, credential_id, public_key, sign_count, transports, created_at, last_used_at, revoked_at, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $this->table('passkeyCredentials')),
            [
                $credential->id,
                $credential->accountId,
                $credential->credentialId,
                $credential->publicKey,
                $credential->signCount,
                DBLayerJson::encode($credential->transports),
                $credential->createdAt,
                $credential->lastUsedAt,
                $credential->revokedAt,
                DBLayerJson::encode($credential->metadata),
            ],
        );
    }

    public function updateUsage(string $credentialId, int $signCount, int $usedAt): void
    {
        $this->execute(
            sprintf('UPDATE %s SET sign_count = ?, last_used_at = ? WHERE credential_id = ? AND revoked_at IS NULL', $this->table('passkeyCredentials')),
            [$signCount, $usedAt, $credentialId],
        );
    }

    private function mapCredential(array $row): PasskeyCredential
    {
        return new PasskeyCredential(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            credentialId: $this->string($row['credential_id'] ?? ''),
            publicKey: $this->string($row['public_key'] ?? ''),
            signCount: $this->int($row['sign_count'] ?? 0),
            transports: array_values(array_filter(
                DBLayerJson::decode($row['transports'] ?? null),
                is_string(...),
            )),
            createdAt: $this->int($row['created_at'] ?? 0),
            lastUsedAt: $this->intOrNull($row['last_used_at'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
