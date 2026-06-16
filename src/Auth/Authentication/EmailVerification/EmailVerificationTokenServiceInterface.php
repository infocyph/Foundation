<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\EmailVerification;

use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

interface EmailVerificationTokenServiceInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function issue(string $accountId, string $email, array $context = []): string;

    public function verify(string $token): TokenVerificationResult;
}
