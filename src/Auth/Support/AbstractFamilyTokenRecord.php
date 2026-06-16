<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

abstract readonly class AbstractFamilyTokenRecord
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public string $familyId,
        public int $issuedAt,
        public int $expiresAt,
        public ?int $rotatedAt = null,
        public ?int $revokedAt = null,
        public array $metadata = [],
    ) {}

    abstract protected function recreate(?int $rotatedAt, ?int $revokedAt): static;

    public function isExpiredAt(?int $timestamp = null): bool
    {
        return $this->expiresAt <= ($timestamp ?? time());
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function withRevokedAt(int $revokedAt): static
    {
        return $this->recreate($this->rotatedAt, $revokedAt);
    }

    public function withRotatedAt(int $rotatedAt): static
    {
        return $this->recreate($rotatedAt, $this->revokedAt);
    }
}
