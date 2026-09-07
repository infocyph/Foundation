<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class MfaChallenge
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public ?string $factorId,
        public string $purpose,
        public int $issuedAt,
        public int $expiresAt,
        public array $metadata = [],
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): ?self
    {
        $id = $payload['id'] ?? null;
        $accountId = $payload['account_id'] ?? null;
        $purpose = $payload['purpose'] ?? null;
        $issuedAt = $payload['issued_at'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;
        if (
            !is_string($id)
            || !is_string($accountId)
            || !is_string($purpose)
            || !is_numeric($issuedAt)
            || !is_numeric($expiresAt)
        ) {
            return null;
        }

        return new self(
            id: $id,
            accountId: $accountId,
            factorId: is_string($payload['factor_id'] ?? null) ? $payload['factor_id'] : null,
            purpose: $purpose,
            issuedAt: (int) $issuedAt,
            expiresAt: (int) $expiresAt,
            metadata: ValueNormalizer::associativeArray($payload['metadata'] ?? null),
        );
    }

    public function isExpiredAt(?int $timestamp = null): bool
    {
        return $this->expiresAt <= ($timestamp ?? time());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->accountId,
            'factor_id' => $this->factorId,
            'purpose' => $this->purpose,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'metadata' => $this->metadata,
        ];
    }
}
