<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Contract;

use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenClaims;

interface OAuthAccessTokenServiceInterface
{
    public function issue(OAuthAccessTokenClaims $claims): string;

    public function verify(string $token, string $expectedAudience): OAuthAccessTokenClaims;
}
