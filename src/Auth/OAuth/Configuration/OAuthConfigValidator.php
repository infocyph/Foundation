<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Configuration;

use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class OAuthConfigValidator
{
    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function validate(bool $production): array
    {
        $enabled = $this->config->get('auth.oauth.enabled', false);
        if (!is_bool($enabled)) {
            return [new ConfigIssue('auth.oauth.enabled must be a boolean.', 'auth.oauth.enabled')];
        }
        if (!$enabled) {
            return [];
        }

        $issues = [];
        $this->validateIssuer($issues, $production);
        $this->validatePositiveInteger($issues, 'auth.oauth.access_token_ttl');
        $this->validatePositiveInteger($issues, 'auth.oauth.authorization_code_ttl');
        $this->validatePositiveInteger($issues, 'auth.oauth.refresh_token_ttl');
        $this->validateGrants($issues);
        $this->validatePkce($issues);
        $this->validateSigning($issues);
        $this->validateRoutes($issues);
        $this->validateDatabase($issues);

        return $issues;
    }

    /** @param list<ConfigIssue> $issues */
    private function validateDatabase(array &$issues): void
    {
        $name = $this->config->get('database.default');
        if (!is_string($name) || trim($name) === '') {
            $issues[] = new ConfigIssue(
                'database.default must be configured when OAuth is enabled.',
                'database.default',
            );

            return;
        }

        $connection = $this->config->get('database.connections.' . trim($name));
        if (!is_array($connection) || $connection === []) {
            $issues[] = new ConfigIssue(
                sprintf('database.connections.%s must exist when OAuth is enabled.', trim($name)),
                'database.connections.' . trim($name),
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateGrants(array &$issues): void
    {
        $grants = $this->config->get('auth.oauth.grants');
        if (!is_array($grants) || $grants === []) {
            $issues[] = new ConfigIssue('auth.oauth.grants must be a non-empty list.', 'auth.oauth.grants');

            return;
        }

        foreach ($grants as $grant) {
            if (is_string($grant) && OAuthGrantType::tryFrom($grant) !== null) {
                continue;
            }

            $issues[] = new ConfigIssue(
                'auth.oauth.grants contains an unsupported grant type.',
                'auth.oauth.grants',
            );

            return;
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateIssuer(array &$issues, bool $production): void
    {
        $issuer = $this->config->get('auth.oauth.issuer');
        if (!is_string($issuer) || trim($issuer) === '' || filter_var($issuer, FILTER_VALIDATE_URL) === false) {
            $issues[] = new ConfigIssue('auth.oauth.issuer must be a valid URL.', 'auth.oauth.issuer');

            return;
        }

        $parts = parse_url($issuer);
        if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
            $issues[] = new ConfigIssue(
                'auth.oauth.issuer must not contain query or fragment components.',
                'auth.oauth.issuer',
            );

            return;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($production && $scheme !== 'https') {
            $issues[] = new ConfigIssue('auth.oauth.issuer must use HTTPS in production.', 'auth.oauth.issuer');
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validatePkce(array &$issues): void
    {
        $methods = $this->config->get('auth.oauth.pkce_methods');
        if (!is_array($methods) || $methods === []) {
            $issues[] = new ConfigIssue('auth.oauth.pkce_methods must contain S256.', 'auth.oauth.pkce_methods');

            return;
        }

        foreach ($methods as $method) {
            if ($method === 'S256') {
                continue;
            }

            $issues[] = new ConfigIssue(
                'auth.oauth.pkce_methods supports only S256 in Foundation 2.1.',
                'auth.oauth.pkce_methods',
            );

            return;
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validatePositiveInteger(array &$issues, string $key): void
    {
        $value = $this->config->get($key);
        if (is_int($value) && $value > 0) {
            return;
        }

        $issues[] = new ConfigIssue(sprintf('%s must be a positive integer.', $key), $key);
    }

    /** @param list<ConfigIssue> $issues */
    private function validateRoutes(array &$issues): void
    {
        foreach (['authorization', 'token', 'revocation', 'introspection', 'jwks'] as $name) {
            $key = 'auth.oauth.routes.' . $name;
            $path = $this->config->get($key);
            if (is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//')) {
                $parts = parse_url($path);
                if (is_array($parts) && !isset($parts['scheme'], $parts['host'], $parts['query'], $parts['fragment'])) {
                    continue;
                }
            }

            $issues[] = new ConfigIssue(sprintf('%s must be a local absolute path.', $key), $key);
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateSigning(array &$issues): void
    {
        foreach (['active_key_id', 'private_key'] as $name) {
            $key = 'auth.oauth.signing.' . $name;
            $value = $this->config->get($key);
            if (is_string($value) && trim($value) !== '') {
                continue;
            }

            $issues[] = new ConfigIssue(sprintf('%s is required when OAuth is enabled.', $key), $key);
        }

        if (!is_array($this->config->get('auth.oauth.signing.public_keys', []))) {
            $issues[] = new ConfigIssue(
                'auth.oauth.signing.public_keys must be a list.',
                'auth.oauth.signing.public_keys',
            );
        }
    }
}
