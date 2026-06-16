<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

interface RefreshTokenServiceInterface
{
    public function issue(RefreshTokenClaims $claims): IssuedRefreshToken;

    public function verify(string $token): TokenVerificationResult;
}
