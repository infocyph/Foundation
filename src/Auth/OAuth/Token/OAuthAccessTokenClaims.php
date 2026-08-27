<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

final readonly class OAuthAccessTokenClaims
{
    public const string TOKEN_USE = 'oauth_access';

    /**
     * @param list<string> $audiences
     * @param list<string> $scopes
     */
    public function __construct(
        public string $issuer,
        public string $subject,
        public array $audiences,
        public int $expiresAt,
        public int $issuedAt,
        public string $tokenId,
        public string $clientId,
        public array $scopes = [],
        public ?string $authorizationId = null,
        public string $tokenUse = self::TOKEN_USE,
    ) {}
}
