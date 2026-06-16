<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

final readonly class PasskeyVerificationResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public bool $verified,
        public ?string $accountId = null,
        public ?string $credentialId = null,
        public ?int $signCount = null,
        public ?string $reason = null,
        public array $context = [],
    ) {}
}
