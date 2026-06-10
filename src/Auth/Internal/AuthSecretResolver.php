<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class AuthSecretResolver
{
    public function __construct(
        private Application $app,
    ) {}

    public function tokenSecret(): string
    {
        $secret = $this->app->config()->get('auth.token_secret', 'foundation-dev-secret');
        $resolved = is_string($secret) && $secret !== ''
            ? $secret
            : 'foundation-dev-secret';

        if ($this->app->config()->isProduction() && $this->isInvalidProductionSecret($resolved)) {
            throw new ConfigurationException('auth.token_secret must be configured in production.');
        }

        if ($this->app->config()->isProduction() && strlen($resolved) < 32) {
            throw new ConfigurationException('auth.token_secret must be at least 32 bytes in production.');
        }

        return $resolved;
    }

    private function isInvalidProductionSecret(string $secret): bool
    {
        return $secret === 'foundation-dev-secret'
            || $secret === 'replace-with-a-production-token-secret';
    }
}
