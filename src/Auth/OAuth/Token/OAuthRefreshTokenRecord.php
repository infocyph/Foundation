<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

final readonly class OAuthRefreshTokenRecord
{
    /**
     * @param list<string> $scopes
     * @param list<string> $audiences
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $tokenHash,
        public string $familyId,
        public string $clientId,
        public ?string $accountId,
        public ?string $deviceId,
        public string $authorizationId,
        public array $scopes,
        public array $audiences,
        public int $issuedAt,
        public int $expiresAt,
        public ?int $rotatedAt = null,
        public ?int $revokedAt = null,
        public array $metadata = [],
    ) {}
}
