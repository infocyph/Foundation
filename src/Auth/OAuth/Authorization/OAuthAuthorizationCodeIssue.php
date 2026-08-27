<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

final readonly class OAuthAuthorizationCodeIssue
{
    public function __construct(
        #[\SensitiveParameter]
        public string $code,
        public OAuthAuthorization $authorization,
        public int $expiresAt,
    ) {}
}
