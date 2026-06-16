<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\RememberMe;

use Infocyph\Foundation\Auth\Support\AbstractFamilyTokenRecord;

final readonly class RememberTokenRecord extends AbstractFamilyTokenRecord
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $deviceId,
        public string $selector,
        public string $verifierHash,
        string $id,
        string $accountId,
        string $familyId,
        int $issuedAt,
        int $expiresAt,
        public ?int $lastUsedAt = null,
        ?int $rotatedAt = null,
        ?int $revokedAt = null,
        array $metadata = [],
    ) {
        parent::__construct($id, $accountId, $familyId, $issuedAt, $expiresAt, $rotatedAt, $revokedAt, $metadata);
    }

    public function withLastUsedAt(int $lastUsedAt): self
    {
        return new self(
            deviceId: $this->deviceId,
            selector: $this->selector,
            verifierHash: $this->verifierHash,
            lastUsedAt: $lastUsedAt,
            id: $this->id,
            accountId: $this->accountId,
            familyId: $this->familyId,
            issuedAt: $this->issuedAt,
            expiresAt: $this->expiresAt,
            rotatedAt: $this->rotatedAt,
            revokedAt: $this->revokedAt,
            metadata: $this->metadata,
        );
    }

    protected function recreate(?int $rotatedAt, ?int $revokedAt): static
    {
        return new self(
            deviceId: $this->deviceId,
            selector: $this->selector,
            verifierHash: $this->verifierHash,
            lastUsedAt: $this->lastUsedAt,
            id: $this->id,
            accountId: $this->accountId,
            familyId: $this->familyId,
            issuedAt: $this->issuedAt,
            expiresAt: $this->expiresAt,
            rotatedAt: $rotatedAt,
            revokedAt: $revokedAt,
            metadata: $this->metadata,
        );
    }
}
