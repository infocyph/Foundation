<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Session;

final readonly class AuthSession
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public ?string $deviceId,
        public int $createdAt,
        public int $lastSeenAt,
        public int $expiresAt,
        public ?int $recentAuthAt = null,
        public array $metadata = [],
    ) {}

    public function isExpiredAt(?int $timestamp = null): bool
    {
        return $this->expiresAt <= ($timestamp ?? time());
    }

    public function seenAt(int $timestamp): self
    {
        return new self(
            id: $this->id,
            accountId: $this->accountId,
            deviceId: $this->deviceId,
            createdAt: $this->createdAt,
            lastSeenAt: $timestamp,
            expiresAt: $this->expiresAt,
            recentAuthAt: $this->recentAuthAt,
            metadata: $this->metadata,
        );
    }
}
