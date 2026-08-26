<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Contract;

use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCode;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCodeConsumeResult;

interface OAuthAuthorizationCodeStoreInterface
{
    public function save(OAuthAuthorizationCode $code): void;

    public function consume(
        string $codeHash,
        string $clientId,
        string $redirectUriHash,
        int $now,
    ): OAuthAuthorizationCodeConsumeResult;
}
