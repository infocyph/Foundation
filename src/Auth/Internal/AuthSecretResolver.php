<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\ArrayKit\Config\Support\Environment;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class AuthSecretResolver
{
    public const string DEFAULT_ENVIRONMENT = 'AUTH_TOKEN_SECRET';

    private const string DEVELOPMENT_SECRET = 'foundation-development-token-secret-change-me-000000000000000000000000';

    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function environmentName(): string
    {
        $configured = $this->config->get('auth.token_secret_environment', self::DEFAULT_ENVIRONMENT);
        if (
            !is_string($configured)
            || preg_match('/\A[A-Z][A-Z0-9_]{1,127}\z/D', $configured) !== 1
        ) {
            throw new ConfigurationException(
                'auth.token_secret_environment must use uppercase shell-variable syntax.',
            );
        }

        return $configured;
    }

    public function tokenSecret(int $minimumBytes = 0): string
    {
        $this->assertNoRawConfiguredSecret();
        $environment = $this->environmentName();
        $secret = Environment::get($environment);
        $resolved = is_string($secret) && $secret !== ''
            ? $secret
            : self::DEVELOPMENT_SECRET;

        if ($this->config->isProduction() && $this->isInvalidProductionSecret($resolved)) {
            throw new ConfigurationException(sprintf(
                '%s must provide the authentication token secret in production.',
                $environment,
            ));
        }

        $requiredBytes = max($minimumBytes, $this->config->isProduction() ? 32 : 0);
        if ($requiredBytes > 0 && strlen($resolved) < $requiredBytes) {
            throw new ConfigurationException(sprintf(
                'Authentication token secret must be at least %d bytes for the selected token policy.',
                $requiredBytes,
            ));
        }

        return $resolved;
    }

    private function assertNoRawConfiguredSecret(): void
    {
        $configured = $this->config->get('auth.token_secret');
        if ($configured === null || $configured === '') {
            return;
        }

        throw new ConfigurationException(
            'Raw auth.token_secret values are not allowed; configure auth.token_secret_environment instead.',
        );
    }

    private function isInvalidProductionSecret(string $secret): bool
    {
        return in_array($secret, [
            'foundation-dev-secret',
            'foundation-development-token-secret-change-me',
            self::DEVELOPMENT_SECRET,
        ], true);
    }
}
