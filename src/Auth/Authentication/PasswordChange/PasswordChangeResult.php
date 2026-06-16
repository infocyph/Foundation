<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\PasswordChange;

final readonly class PasswordChangeResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public PasswordChangeStatus $status,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === PasswordChangeStatus::CHANGED;
    }
}
