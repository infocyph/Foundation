<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

interface MfaVerifierInterface
{
    public function verify(MfaChallenge $challenge, string $code): MfaVerificationResult;
}
