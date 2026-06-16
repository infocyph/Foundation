<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

use Infocyph\Foundation\Auth\Support\AbstractFamilyTokenRecord;

final readonly class RefreshTokenRecord extends AbstractFamilyTokenRecord
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $tokenHash,
        public ?string $clientId,
        public ?string $deviceId,
        string $id,
        string $accountId,
        string $familyId,
        int $issuedAt,
        int $expiresAt,
        ?int $rotatedAt = null,
        ?int $revokedAt = null,
        array $metadata = [],
    ) {
        parent::__construct($id, $accountId, $familyId, $issuedAt, $expiresAt, $rotatedAt, $revokedAt, $metadata);
    }

    protected function recreate(?int $rotatedAt, ?int $revokedAt): static
    {
        return new self(
            tokenHash: $this->tokenHash,
            clientId: $this->clientId,
            deviceId: $this->deviceId,
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
