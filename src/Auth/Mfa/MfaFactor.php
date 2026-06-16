<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

final readonly class MfaFactor
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public string $type,
        public string $label,
        public bool $enabled,
        public int $createdAt,
        public array $metadata = [],
    ) {}

    public function activated(): self
    {
        return new self(
            id: $this->id,
            accountId: $this->accountId,
            type: $this->type,
            label: $this->label,
            enabled: true,
            createdAt: $this->createdAt,
            metadata: $this->metadata,
        );
    }
}
