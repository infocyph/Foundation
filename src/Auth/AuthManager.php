<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Auth\Authentication\Login\AuthenticatorInterface;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\ConfigValidator;

final readonly class AuthManager
{
    /**
     * @param array<string, string> $drivers
     */
    public function __construct(
        private AuthServices $services,
        private ConfigRepository $config,
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

    public function isProductionReady(): bool
    {
        return !(new ConfigValidator($this->config))->validateForProduction()->fails();
    }

    /**
     * @return array{
     *   production_ready: bool,
     *   issues: list<string>,
     *   drivers: array<string, string>
     * }
     */
    public function readinessReport(): array
    {
        $result = (new ConfigValidator($this->config))->validateForProduction();

        return [
            'production_ready' => !$result->fails(),
            'issues' => $result->messages(),
            'drivers' => $this->drivers(),
        ];
    }

    public function services(): AuthServices
    {
        return $this->services;
    }
}
