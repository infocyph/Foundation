<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\RememberMe;

interface RememberTokenServiceInterface
{
    /**
     * Must return selector, family identifier, verifier hash, and expiry for durable storage.
     */
    public function issue(string $accountId, string $deviceId): RememberToken;

    public function verify(string $token): RememberTokenVerificationResult;
}
