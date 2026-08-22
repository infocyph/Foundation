<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class AuthSecretResolver
{
    private const string DEVELOPMENT_SECRET = 'foundation-development-token-secret-change-me';

    public function __construct(
        private Application $app,
    ) {}

    public function tokenSecret(int $minimumBytes = 0): string
    {
        $secret = $this->app->config()->get('auth.token_secret', self::DEVELOPMENT_SECRET);
        $resolved = is_string($secret) && $secret !== ''
            ? $secret
            : self::DEVELOPMENT_SECRET;

        if ($this->app->config()->isProduction() && $this->isInvalidProductionSecret($resolved)) {
            throw new ConfigurationException('auth.token_secret must be configured in production.');
        }

        $requiredBytes = max($minimumBytes, $this->app->config()->isProduction() ? 32 : 0);
        if ($requiredBytes > 0 && strlen($resolved) < $requiredBytes) {
            throw new ConfigurationException(sprintf(
                'auth.token_secret must be at least %d bytes for the selected token policy.',
                $requiredBytes,
            ));
        }

        return $resolved;
    }

    private function isInvalidProductionSecret(string $secret): bool
    {
        return in_array($secret, [
            'foundation-dev-secret',
            self::DEVELOPMENT_SECRET,
        ], true);
    }
}
