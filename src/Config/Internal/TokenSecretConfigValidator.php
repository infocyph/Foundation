<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config\Internal;

use Infocyph\ArrayKit\Config\Support\Environment;
use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class TokenSecretConfigValidator
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    /** @return list<ConfigIssue> */
    public function validate(int $minimumBytes, bool $required = true): array
    {
        $issues = [];
        $raw = $this->config->get('auth.token_secret');
        if ($raw !== null && $raw !== '') {
            $issues[] = new ConfigIssue(
                'Raw auth.token_secret values are not allowed; use auth.token_secret_environment.',
                'auth.token_secret',
            );
        }

        $environment = $this->environmentName();
        if ($environment === null) {
            $issues[] = new ConfigIssue(
                'auth.token_secret_environment must use uppercase shell-variable syntax.',
                'auth.token_secret_environment',
            );

            return $issues;
        }

        $secret = Environment::get($environment);
        if (!is_string($secret) || $secret === '') {
            if ($required) {
                $issues[] = new ConfigIssue(
                    sprintf(
                        '%s must provide the authentication token secret for the selected production token policy.',
                        $environment,
                    ),
                    'auth.token_secret_environment',
                );
            }

            return $issues;
        }

        if ($this->developmentPlaceholder($secret)) {
            $issues[] = new ConfigIssue(
                'The authentication token secret must not use a development placeholder.',
                'auth.token_secret_environment',
            );

            return $issues;
        }

        if (strlen($secret) < $minimumBytes) {
            $issues[] = new ConfigIssue(
                sprintf(
                    'Authentication token secret must be at least %d bytes for the selected token policy.',
                    $minimumBytes,
                ),
                'auth.token_secret_environment',
            );
        }

        return $issues;
    }

    private function developmentPlaceholder(string $secret): bool
    {
        return in_array($secret, [
            'foundation-dev-secret',
            'foundation-development-token-secret-change-me',
            'foundation-development-token-secret-change-me-000000000000000000000000',
        ], true);
    }

    private function environmentName(): ?string
    {
        $configured = $this->config->get('auth.token_secret_environment', 'AUTH_TOKEN_SECRET');
        if (
            !is_string($configured)
            || preg_match('/\A[A-Z][A-Z0-9_]{1,127}\z/D', $configured) !== 1
        ) {
            return null;
        }

        return $configured;
    }
}
