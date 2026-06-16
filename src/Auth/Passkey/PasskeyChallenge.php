<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

final readonly class PasskeyChallenge
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public ?string $accountId,
        public string $purpose,
        public string $challenge,
        public int $issuedAt,
        public int $expiresAt,
        public array $metadata = [],
    ) {}

    public function isExpiredAt(?int $timestamp = null): bool
    {
        return $this->expiresAt <= ($timestamp ?? time());
    }
}
