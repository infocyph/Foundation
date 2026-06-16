<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

final readonly class PasskeyCredential
{
    /**
     * @param list<string> $transports
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public string $credentialId,
        public string $publicKey,
        public int $signCount,
        public array $transports,
        public int $createdAt,
        public ?int $lastUsedAt = null,
        public ?int $revokedAt = null,
        public array $metadata = [],
    ) {}

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function revokedAt(int $timestamp): self
    {
        return new self(
            id: $this->id,
            accountId: $this->accountId,
            credentialId: $this->credentialId,
            publicKey: $this->publicKey,
            signCount: $this->signCount,
            transports: $this->transports,
            createdAt: $this->createdAt,
            lastUsedAt: $this->lastUsedAt,
            revokedAt: $timestamp,
            metadata: $this->metadata,
        );
    }
}
