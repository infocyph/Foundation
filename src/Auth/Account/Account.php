<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Account;

final readonly class Account implements AccountInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $id,
        private string $identifier,
        private AccountStatus $status = AccountStatus::ACTIVE,
        private ?string $passwordHash = null,
        private array $metadata = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            id: $this->id,
            identifier: $this->identifier,
            status: $this->status,
            passwordHash: $this->passwordHash,
            metadata: $metadata,
        );
    }

    public function withPasswordHash(string $passwordHash): self
    {
        return new self(
            id: $this->id,
            identifier: $this->identifier,
            status: $this->status,
            passwordHash: $passwordHash,
            metadata: $this->metadata,
        );
    }

    public function withStatus(AccountStatus $status): self
    {
        return new self(
            id: $this->id,
            identifier: $this->identifier,
            status: $status,
            passwordHash: $this->passwordHash,
            metadata: $this->metadata,
        );
    }
}
