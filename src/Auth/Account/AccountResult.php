<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Account;

final readonly class AccountResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public AccountActionStatus $status,
        public ?AccountInterface $account = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === AccountActionStatus::CREATED
            || $this->status === AccountActionStatus::UPDATED;
    }
}
