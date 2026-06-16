<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Security;

final readonly class TokenVerificationResult
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public bool $verified,
        public ?string $subjectId = null,
        public ?string $tokenId = null,
        public array $claims = [],
        public ?int $expiresAt = null,
        public ?string $failureReason = null,
    ) {}
}
