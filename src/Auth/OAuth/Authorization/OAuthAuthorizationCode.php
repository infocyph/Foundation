<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

final readonly class OAuthAuthorizationCode
{
    /**
     * @param list<string> $scopes
     * @param list<string> $audiences
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $codeHash,
        public string $clientId,
        public string $accountId,
        public string $authorizationId,
        public string $redirectUriHash,
        public string $pkceChallenge,
        public array $scopes,
        public array $audiences,
        public int $issuedAt,
        public int $expiresAt,
        public ?int $consumedAt = null,
        public array $metadata = [],
    ) {}
}
