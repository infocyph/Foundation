<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Device;

final readonly class DeviceRecord
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public ?string $label,
        public ?string $fingerprint,
        public bool $trusted,
        public int $createdAt,
        public ?int $lastSeenAt = null,
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
            label: $this->label,
            fingerprint: $this->fingerprint,
            trusted: false,
            createdAt: $this->createdAt,
            lastSeenAt: $this->lastSeenAt,
            revokedAt: $timestamp,
            metadata: $this->metadata,
        );
    }

    public function seenAt(int $timestamp): self
    {
        return new self(
            id: $this->id,
            accountId: $this->accountId,
            label: $this->label,
            fingerprint: $this->fingerprint,
            trusted: $this->trusted,
            createdAt: $this->createdAt,
            lastSeenAt: $timestamp,
            revokedAt: $this->revokedAt,
            metadata: $this->metadata,
        );
    }

    public function trusted(): self
    {
        return new self(
            id: $this->id,
            accountId: $this->accountId,
            label: $this->label,
            fingerprint: $this->fingerprint,
            trusted: true,
            createdAt: $this->createdAt,
            lastSeenAt: $this->lastSeenAt,
            revokedAt: $this->revokedAt,
            metadata: $this->metadata,
        );
    }
}
