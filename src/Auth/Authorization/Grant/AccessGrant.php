<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Grant;

final readonly class AccessGrant
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $principalId,
        public string $permission,
        public ?string $resourceType = null,
        public ?string $resourceId = null,
        public ?int $expiresAt = null,
        public ?int $revokedAt = null,
        public array $metadata = [],
    ) {}

    public function isExpiredAt(?int $timestamp = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= ($timestamp ?? time());
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }
}
