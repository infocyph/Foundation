<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

final readonly class RecoveryCodeVerificationResult
{
    public function __construct(
        public bool $verified,
        public ?string $reason = null,
    ) {}
}
