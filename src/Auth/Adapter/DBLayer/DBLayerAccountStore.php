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
    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function findById(string $id): ?AccountInterface
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('accounts')),
            [$id],
        );

        return $row === null ? null : $this->mapAccount($row);
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function findByIdentifier(string $identifier): ?AccountInterface
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE identifier = ?', $this->table('accounts')),
            [$identifier],
        );

        return $row === null ? null : $this->mapAccount($row);
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
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

    /**
     * Compare the original database row at the update boundary. The email may
     * live in a username account's JSON metadata, so a separate email-column
     * comparison is insufficient.
     *
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function markVerifiedForEmail(string $accountId, string $email, int $verifiedAt): bool
    {
        $row = $this->first(
            sprintf('SELECT identifier, status, metadata FROM %s WHERE id = ?', $this->table('accounts')),
            [$accountId],
        );
        if ($row === null) {
            return false;
        }

        $identifier = $this->string($row['identifier'] ?? null);
        $originalMetadata = $row['metadata'] ?? null;
        $metadata = DBLayerJson::decode($originalMetadata);
        $storedEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false
            ? $identifier
            : ($metadata['email'] ?? null);
        if (!is_string($storedEmail)
            || filter_var($storedEmail, FILTER_VALIDATE_EMAIL) === false
            || strcasecmp($storedEmail, $email) !== 0
        ) {
            return false;
        }

        $status = AccountStatus::from($this->string($row['status'] ?? null));
        $metadata['verified_at'] = $verifiedAt;
        $where = 'id = ? AND identifier = ? AND status = ?';
        $bindings = [$accountId, $identifier, $status->value];
        if ($originalMetadata === null) {
            $where .= ' AND metadata IS NULL';
        } elseif (is_string($originalMetadata)) {
            $condition = match ($this->connection()->getDriverName()) {
                'pgsql' => 'CAST(metadata AS TEXT) = ?',
                'mysql', 'mariadb' => 'CAST(metadata AS BINARY) = CAST(? AS BINARY)',
                'mssql' => 'CONVERT(VARBINARY(MAX), metadata) = CONVERT(VARBINARY(MAX), CONVERT(NVARCHAR(MAX), ?))',
                'sqlite' => 'metadata = ?',
                default => throw new \LogicException('Atomic account email verification requires a supported database driver.'),
            };
            $where .= ' AND ' . $condition;
            $bindings[] = $originalMetadata;
        } else {
            return false;
        }

        return $this->query('accounts')->whereRaw($where, $bindings)->update([
            'status' => $status === AccountStatus::PENDING_VERIFICATION
                ? AccountStatus::ACTIVE->value
                : $status->value,
            'metadata' => DBLayerJson::encode($metadata),
        ]) === 1;
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
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

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function updateMetadata(string $accountId, array $metadata): void
    {
        $this->execute(
            sprintf('UPDATE %s SET metadata = ? WHERE id = ?', $this->table('accounts')),
            [DBLayerJson::encode($metadata), $accountId],
        );
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function updatePasswordHash(string $accountId, string $passwordHash): void
    {
        $this->execute(
            sprintf('UPDATE %s SET password_hash = ? WHERE id = ?', $this->table('accounts')),
            [$passwordHash, $accountId],
        );
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function updateStatus(string $accountId, AccountStatus $status): void
    {
        $this->execute(
            sprintf('UPDATE %s SET status = ? WHERE id = ?', $this->table('accounts')),
            [$status->value, $accountId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
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
