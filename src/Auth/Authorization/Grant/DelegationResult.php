<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Grant;

final readonly class DelegationResult
{
    /**
     * @param list<AccessGrant> $grants
     * @param array<string, mixed> $context
     */
    public function __construct(
        public DelegationStatus $status,
        public ?AccessGrant $grant = null,
        public array $grants = [],
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
            DelegationStatus::GRANTED,
            DelegationStatus::LISTED,
            DelegationStatus::REVOKED => true,
        };
    }
}
