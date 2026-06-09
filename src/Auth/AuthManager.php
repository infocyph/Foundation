<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\AuthLayer\Authentication\Login\AuthenticatorInterface;
use Infocyph\AuthLayer\Authorization\Gate\AuthorizerInterface;
use Infocyph\AuthLayer\Principal\CurrentPrincipalContext;

final readonly class AuthManager
{
    /**
     * @param array<string, string> $drivers
     */
    public function __construct(
        private AuthServices $services,
        private array $drivers = [],
    ) {}

    public function authenticator(): AuthenticatorInterface
    {
        return $this->services->authenticator;
    }

    public function authorizer(): AuthorizerInterface
    {
        return $this->services->authorizer;
    }

    public function principal(): CurrentPrincipalContext
    {
        return $this->services->principals;
    }

    public function driver(string $name, ?string $default = null): ?string
    {
        $driver = $this->drivers[$name] ?? $default;

        return is_string($driver) ? $driver : $default;
    }

    /**
     * @return array<string, string>
     */
    public function drivers(): array
    {
        return $this->drivers;
    }

    public function services(): AuthServices
    {
        return $this->services;
    }
}
