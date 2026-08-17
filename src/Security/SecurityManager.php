<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Support\AbstractContainerManager;

final readonly class SecurityManager extends AbstractContainerManager
{
    public function accessTokens(): AccessTokenServiceInterface
    {
        return $this->typedService(AccessTokenServiceInterface::class, 'Security access token service must resolve correctly.');
    }

    public function passwordHasher(): PasswordHasherInterface
    {
        return $this->typedService(PasswordHasherInterface::class, 'Security password hasher must resolve correctly.');
    }

    public function passwordPolicy(): PasswordPolicyInterface
    {
        return $this->typedService(PasswordPolicyInterface::class, 'Security password policy must resolve correctly.');
    }

    public function passwordVerifier(): PasswordVerifierInterface
    {
        return $this->typedService(PasswordVerifierInterface::class, 'Security password verifier must resolve correctly.');
    }

    public function refreshTokens(): RefreshTokenServiceInterface
    {
        return $this->typedService(RefreshTokenServiceInterface::class, 'Security refresh token service must resolve correctly.');
    }

    protected function configSection(): string
    {
        return 'security';
    }
}
