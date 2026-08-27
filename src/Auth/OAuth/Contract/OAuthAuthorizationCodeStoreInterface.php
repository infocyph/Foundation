<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Contract;

use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCode;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCodeConsumeResult;

interface OAuthAuthorizationCodeStoreInterface
{
    public function consume(
        string $codeHash,
        string $clientId,
        string $redirectUriHash,
        string $pkceChallenge,
        int $now,
    ): OAuthAuthorizationCodeConsumeResult;

    public function save(OAuthAuthorizationCode $code): void;
}
