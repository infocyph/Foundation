<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Account;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class AccountManager
{
    public function __construct(
        private AccountProviderInterface $accounts,
        private AccountStoreInterface $store,
        private AuthIdGeneratorInterface $ids,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public function create(string $identifier, ?string $passwordHash = null, array $metadata = [], AccountStatus $status = AccountStatus::ACTIVE): AccountResult
    {
        if ($this->accounts->findByIdentifier($identifier) !== null) {
            return new AccountResult(AccountActionStatus::ALREADY_EXISTS, code: 'account_already_exists', context: $metadata);
        }

        $account = new Account($this->ids->accountId(), $identifier, $status, $passwordHash, $metadata);
        $this->store->save($account);

        return new AccountResult(AccountActionStatus::CREATED, $account, 'account_created', $metadata);
    }

    public function disable(string $accountId): AccountResult
    {
        return $this->updateStatus($accountId, AccountStatus::DISABLED, 'account_disabled');
    }

    public function lock(string $accountId): AccountResult
    {
        return $this->updateStatus($accountId, AccountStatus::LOCKED, 'account_locked');
    }

    public function markVerified(string $accountId): AccountResult
    {
        $account = $this->accounts->findById($accountId);

        if ($account === null) {
            return new AccountResult(AccountActionStatus::NOT_FOUND, code: 'account_not_found');
        }

        $this->store->markVerified($accountId, $this->clock->now());

        return new AccountResult(AccountActionStatus::UPDATED, $this->accounts->findById($accountId), 'account_verified');
    }

    public function requireMfaEnrollment(string $accountId): AccountResult
    {
        return $this->updateStatus($accountId, AccountStatus::MFA_ENROLLMENT_REQUIRED, 'mfa_enrollment_required');
    }

    public function requirePasswordChange(string $accountId): AccountResult
    {
        return $this->updateStatus($accountId, AccountStatus::PASSWORD_CHANGE_REQUIRED, 'password_change_required');
    }

    public function suspend(string $accountId): AccountResult
    {
        return $this->updateStatus($accountId, AccountStatus::SUSPENDED, 'account_suspended');
    }

    public function unlock(string $accountId): AccountResult
    {
        return $this->updateStatus($accountId, AccountStatus::ACTIVE, 'account_unlocked');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function updateMetadata(string $accountId, array $metadata): AccountResult
    {
        $account = $this->accounts->findById($accountId);

        if ($account === null) {
            return new AccountResult(AccountActionStatus::NOT_FOUND, code: 'account_not_found');
        }

        $this->store->updateMetadata($accountId, $metadata);

        return new AccountResult(AccountActionStatus::UPDATED, $this->accounts->findById($accountId), 'account_metadata_updated', $metadata);
    }

    private function updateStatus(string $accountId, AccountStatus $status, string $code): AccountResult
    {
        $account = $this->accounts->findById($accountId);

        if ($account === null) {
            return new AccountResult(AccountActionStatus::NOT_FOUND, code: 'account_not_found');
        }

        $this->store->updateStatus($accountId, $status);

        return new AccountResult(AccountActionStatus::UPDATED, $this->accounts->findById($accountId), $code);
    }
}
