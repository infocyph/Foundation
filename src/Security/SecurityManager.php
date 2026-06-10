<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\AuthLayer\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\AuthLayer\Contract\Security\AccessTokenServiceInterface;
use Infocyph\AuthLayer\Contract\Security\PasswordHasherInterface;
use Infocyph\AuthLayer\Contract\Security\PasswordPolicyInterface;
use Infocyph\AuthLayer\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\Container;

final readonly class SecurityManager
{
    public function __construct(
        private ConfigRepository $config,
        private Container $container,
    ) {}

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('security', []);
        }

        return $this->config->get('security.' . $key, $default);
    }

    public function passwordHasher(): PasswordHasherInterface
    {
        return $this->container->get(PasswordHasherInterface::class);
    }

    public function passwordPolicy(): PasswordPolicyInterface
    {
        return $this->container->get(PasswordPolicyInterface::class);
    }

    public function passwordVerifier(): PasswordVerifierInterface
    {
        return $this->container->get(PasswordVerifierInterface::class);
    }

    public function accessTokens(): AccessTokenServiceInterface
    {
        return $this->container->get(AccessTokenServiceInterface::class);
    }

    public function refreshTokens(): RefreshTokenServiceInterface
    {
        return $this->container->get(RefreshTokenServiceInterface::class);
    }
}
