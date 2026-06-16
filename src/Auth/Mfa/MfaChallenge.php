<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

final readonly class MfaChallenge
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public ?string $factorId,
        public string $purpose,
        public int $issuedAt,
        public int $expiresAt,
        public array $metadata = [],
    ) {}

    public function isExpiredAt(?int $timestamp = null): bool
    {
        return $this->expiresAt <= ($timestamp ?? time());
    }
}
