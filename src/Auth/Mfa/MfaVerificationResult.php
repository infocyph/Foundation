<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

final readonly class MfaVerificationResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public bool $verified,
        public ?string $factorId = null,
        public bool $recoveryCodeUsed = false,
        public ?string $reason = null,
        public array $context = [],
    ) {}
}
