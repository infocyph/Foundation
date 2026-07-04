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
        return $this->typedService(
            AccessTokenServiceInterface::class,
            'Security access token service must resolve to AccessTokenServiceInterface.',
        );
    }

    public function passwordHasher(): PasswordHasherInterface
    {
        return $this->typedService(
            PasswordHasherInterface::class,
            'Security password hasher must resolve to PasswordHasherInterface.',
        );
    }

    public function passwordPolicy(): PasswordPolicyInterface
    {
        return $this->typedService(
            PasswordPolicyInterface::class,
            'Security password policy must resolve to PasswordPolicyInterface.',
        );
    }

    public function passwordVerifier(): PasswordVerifierInterface
    {
        return $this->typedService(
            PasswordVerifierInterface::class,
            'Security password verifier must resolve to PasswordVerifierInterface.',
        );
    }

    public function refreshTokens(): RefreshTokenServiceInterface
    {
        return $this->typedService(
            RefreshTokenServiceInterface::class,
            'Security refresh token service must resolve to RefreshTokenServiceInterface.',
        );
    }

    protected function configSection(): string
    {
        return 'security';
    }
}
