<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

final readonly class RefreshTokenClaims
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $tokenId,
        public string $accountId,
        public string $familyId,
        public ?string $clientId,
        public ?string $deviceId,
        public int $issuedAt,
        public int $expiresAt,
        public array $metadata = [],
    ) {}
}
