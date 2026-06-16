<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Passwordless;

use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

interface PasswordlessTokenServiceInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function issue(string $identifier, array $context = []): string;

    public function verify(string $token): TokenVerificationResult;
}
