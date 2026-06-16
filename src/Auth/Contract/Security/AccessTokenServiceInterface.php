<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Security;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;

interface AccessTokenServiceInterface
{
    public function issue(AccessTokenClaims $claims): string;

    public function verify(string $token): TokenVerificationResult;
}
