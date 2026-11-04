<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;
use Infocyph\Foundation\Auth\Exception\StorageException;

final class InMemoryAccountStore implements AccountProviderInterface, AccountStoreInterface
{
    /**
     * @var array<string, AccountInterface>
     */
    private array $accounts = [];

    #[\Override]
    public function findById(string $id): ?AccountInterface
    {
        return $this->accounts[$id] ?? null;
    }

    #[\Override]
    public function findByIdentifier(string $identifier): ?AccountInterface
    {
        foreach ($this->accounts as $account) {
            if ($account->identifier() === $identifier) {
                return $account;
            }
        }

        return null;
    }

    /** @throws \Infocyph\Foundation\Auth\Exception\StorageException */
    #[\Override]
    public function markVerified(string $accountId, int $verifiedAt): void
    {
        $account = $this->requireConcreteAccount($accountId);
        $metadata = $account->metadata();
        $metadata['verified_at'] = $verifiedAt;

        $updated = $account
            ->withMetadata($metadata)
            ->withStatus($account->status() === AccountStatus::PENDING_VERIFICATION ? AccountStatus::ACTIVE : $account->status());

        $this->accounts[$accountId] = $updated;
    }

    #[\Override]
    public function markVerifiedForEmail(string $accountId, string $email, int $verifiedAt): bool
    {
        $account = $this->accounts[$accountId] ?? null;
        if (!$account instanceof Account) {
            return false;
        }

        $identifier = $account->identifier();
        $storedEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false
            ? $identifier
            : ($account->metadata()['email'] ?? null);
        if (!is_string($storedEmail)
            || filter_var($storedEmail, FILTER_VALIDATE_EMAIL) === false
            || strcasecmp($storedEmail, $email) !== 0
        ) {
            return false;
        }

        $metadata = $account->metadata();
        $metadata['verified_at'] = $verifiedAt;
        $this->accounts[$accountId] = $account
            ->withMetadata($metadata)
            ->withStatus($account->status() === AccountStatus::PENDING_VERIFICATION
                ? AccountStatus::ACTIVE
                : $account->status());

        return true;
    }

    #[\Override]
    public function save(AccountInterface $account): void
    {
        $this->accounts[$account->id()] = $account;
    }

    /** @throws \Infocyph\Foundation\Auth\Exception\StorageException */
    #[\Override]
    public function updateMetadata(string $accountId, array $metadata): void
    {
        $account = $this->requireConcreteAccount($accountId);
        $this->accounts[$accountId] = $account->withMetadata($metadata);
    }

    /** @throws \Infocyph\Foundation\Auth\Exception\StorageException */
    #[\Override]
    public function updatePasswordHash(string $accountId, string $passwordHash): void
    {
        $account = $this->requireConcreteAccount($accountId);
        $this->accounts[$accountId] = $account->withPasswordHash($passwordHash);
    }

    /** @throws \Infocyph\Foundation\Auth\Exception\StorageException */
    #[\Override]
    public function updateStatus(string $accountId, AccountStatus $status): void
    {
        $account = $this->requireConcreteAccount($accountId);
        $this->accounts[$accountId] = $account->withStatus($status);
    }

    /**
     * @throws \Infocyph\Foundation\Auth\Exception\StorageException
     */
    private function requireAccount(string $accountId): AccountInterface
    {
        $account = $this->accounts[$accountId] ?? null;

        if ($account === null) {
            throw new StorageException(sprintf('Account "%s" was not found.', $accountId));
        }

        return $account;
    }

    /**
     * @throws \Infocyph\Foundation\Auth\Exception\StorageException
     */
    private function requireConcreteAccount(string $accountId): Account
    {
        $account = $this->requireAccount($accountId);

        if (!$account instanceof Account) {
            throw new StorageException(sprintf('Account "%s" must be an %s instance for in-memory mutation.', $accountId, Account::class));
        }

        return $account;
    }
}
