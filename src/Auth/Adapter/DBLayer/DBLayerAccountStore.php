<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;

final readonly class DBLayerAccountStore extends DBLayerStore implements AccountProviderInterface, AccountStoreInterface
{
    public function findById(string $id): ?AccountInterface
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('accounts')),
            [$id],
        );

        return $row === null ? null : $this->mapAccount($row);
    }

    public function findByIdentifier(string $identifier): ?AccountInterface
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE identifier = ?', $this->table('accounts')),
            [$identifier],
        );

        return $row === null ? null : $this->mapAccount($row);
    }

    public function markVerified(string $accountId, int $verifiedAt): void
    {
        $account = $this->findById($accountId);
        if ($account === null) {
            return;
        }

        $metadata = $account->metadata();
        $metadata['verified_at'] = $verifiedAt;
        $status = $account->status() === AccountStatus::PENDING_VERIFICATION
            ? AccountStatus::ACTIVE
            : $account->status();

        $this->execute(
            sprintf('UPDATE %s SET status = ?, metadata = ? WHERE id = ?', $this->table('accounts')),
            [$status->value, DBLayerJson::encode($metadata), $accountId],
        );
    }

    public function save(AccountInterface $account): void
    {
        if ($this->findById($account->id()) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET identifier = ?, status = ?, password_hash = ?, metadata = ? WHERE id = ?', $this->table('accounts')),
                [
                    $account->identifier(),
                    $account->status()->value,
                    $account->passwordHash(),
                    DBLayerJson::encode($account->metadata()),
                    $account->id(),
                ],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, identifier, status, password_hash, metadata) VALUES (?, ?, ?, ?, ?)', $this->table('accounts')),
            [
                $account->id(),
                $account->identifier(),
                $account->status()->value,
                $account->passwordHash(),
                DBLayerJson::encode($account->metadata()),
            ],
        );
    }

    public function updateMetadata(string $accountId, array $metadata): void
    {
        $this->execute(
            sprintf('UPDATE %s SET metadata = ? WHERE id = ?', $this->table('accounts')),
            [DBLayerJson::encode($metadata), $accountId],
        );
    }

    public function updatePasswordHash(string $accountId, string $passwordHash): void
    {
        $this->execute(
            sprintf('UPDATE %s SET password_hash = ? WHERE id = ?', $this->table('accounts')),
            [$passwordHash, $accountId],
        );
    }

    public function updateStatus(string $accountId, AccountStatus $status): void
    {
        $this->execute(
            sprintf('UPDATE %s SET status = ? WHERE id = ?', $this->table('accounts')),
            [$status->value, $accountId],
        );
    }

    private function mapAccount(array $row): Account
    {
        return new Account(
            id: $this->string($row['id'] ?? ''),
            identifier: $this->string($row['identifier'] ?? ''),
            status: AccountStatus::from($this->string($row['status'] ?? AccountStatus::ACTIVE->value, AccountStatus::ACTIVE->value)),
            passwordHash: $this->stringOrNull($row['password_hash'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
