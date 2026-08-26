<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

final readonly class OAuthRefreshTokenIssue
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        public OAuthRefreshTokenRecord $record,
    ) {}
}
