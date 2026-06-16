<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

interface PasskeyServiceInterface
{
    public function finishAuthentication(PasskeyAuthenticationResult $result): PasskeyVerificationResult;

    public function finishRegistration(PasskeyRegistrationResult $result): PasskeyCredential;

    public function startAuthentication(?string $accountId = null): PasskeyChallenge;

    public function startRegistration(string $accountId): PasskeyChallenge;
}
