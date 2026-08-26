<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

final readonly class OAuthAccessTokenRevocation
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $tokenId,
        public string $clientId,
        public ?string $authorizationId,
        public int $expiresAt,
        public int $revokedAt,
        public ?string $reason = null,
        public array $metadata = [],
    ) {}
}
