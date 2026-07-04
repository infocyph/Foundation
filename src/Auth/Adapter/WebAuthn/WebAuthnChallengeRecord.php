<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class WebAuthnChallengeRecord
{
    /**
     * @param list<string> $allowedCredentialIds
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public ?string $accountId,
        public string $purpose,
        public string $challenge,
        public int $issuedAt,
        public int $expiresAt,
        public array $allowedCredentialIds = [],
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $id = $payload['id'] ?? null;
        $purpose = $payload['purpose'] ?? null;
        $challenge = $payload['challenge'] ?? null;
        $issuedAt = $payload['issued_at'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;

        if (!is_string($id) || !is_string($purpose) || !is_string($challenge) || !is_numeric($issuedAt) || !is_numeric($expiresAt)) {
            return null;
        }

        return new self(
            id: $id,
            accountId: is_string($payload['account_id'] ?? null) ? $payload['account_id'] : null,
            purpose: $purpose,
            challenge: $challenge,
            issuedAt: (int) $issuedAt,
            expiresAt: (int) $expiresAt,
            allowedCredentialIds: ValueNormalizer::stringList($payload['allowed_credential_ids'] ?? []),
            metadata: ValueNormalizer::associativeArray($payload['metadata'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->accountId,
            'purpose' => $this->purpose,
            'challenge' => $this->challenge,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'allowed_credential_ids' => $this->allowedCredentialIds,
            'metadata' => $this->metadata,
        ];
    }
}
