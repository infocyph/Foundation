<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

final readonly class MfaEnrollmentResult
{
    /**
     * @param list<string> $recoveryCodes
     * @param array<string, mixed> $context
     */
    public function __construct(
        public MfaStatus $status,
        public ?MfaFactor $factor = null,
        public array $recoveryCodes = [],
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === MfaStatus::ENROLLED
            || $this->status === MfaStatus::ACTIVATED
            || $this->status === MfaStatus::REMOVED;
    }
}
