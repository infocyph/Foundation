<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\RememberMe;

final readonly class RememberTokenVerificationResult
{
    public function __construct(
        public bool $verified,
        public ?RememberTokenRecord $record = null,
        public bool $suspiciousReuse = false,
        public ?string $failureReason = null,
    ) {}
}
