<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

final readonly class TokenRevocationResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public TokenRevocationStatus $status,
        public string $familyId,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return match ($this->status) {
            TokenRevocationStatus::REVOKED,
            TokenRevocationStatus::ALREADY_REVOKED => true,
        };
    }
}
