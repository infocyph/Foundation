<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Exception\PasskeyException;
use Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyChallenge;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Auth\Passkey\PasskeyRegistrationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyVerificationResult;

final class DisabledPasskeyService implements PasskeyServiceInterface
{
    public function finishAuthentication(PasskeyAuthenticationResult $result): PasskeyVerificationResult
    {
        unset($result);

        throw new PasskeyException('Passkeys are disabled for this Foundation application.');
    }

    public function finishRegistration(PasskeyRegistrationResult $result): PasskeyCredential
    {
        unset($result);

        throw new PasskeyException('Passkeys are disabled for this Foundation application.');
    }

    public function startAuthentication(?string $accountId = null): PasskeyChallenge
    {
        unset($accountId);

        throw new PasskeyException('Passkeys are disabled for this Foundation application.');
    }

    public function startRegistration(string $accountId): PasskeyChallenge
    {
        unset($accountId);

        throw new PasskeyException('Passkeys are disabled for this Foundation application.');
    }
}
