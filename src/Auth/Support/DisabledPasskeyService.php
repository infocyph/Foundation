<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\AuthLayer\Exception\PasskeyException;
use Infocyph\AuthLayer\Passkey\PasskeyAuthenticationResult;
use Infocyph\AuthLayer\Passkey\PasskeyChallenge;
use Infocyph\AuthLayer\Passkey\PasskeyCredential;
use Infocyph\AuthLayer\Passkey\PasskeyRegistrationResult;
use Infocyph\AuthLayer\Passkey\PasskeyServiceInterface;
use Infocyph\AuthLayer\Passkey\PasskeyVerificationResult;

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
