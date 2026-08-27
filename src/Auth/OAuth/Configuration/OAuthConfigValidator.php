<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Configuration;

use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\SharedStateTopology;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class OAuthConfigValidator
{
    private const array SIGNING_ALGORITHMS = [
        'RS256', 'RS384', 'RS512',
        'PS256', 'PS384', 'PS512',
        'ES256', 'ES384', 'ES512',
        'EdDSA',
    ];

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
        $this->validatePositiveInteger($issues, 'auth.oauth.authorization_code_ttl', 60);
        $this->validatePositiveInteger($issues, 'auth.oauth.refresh_token_ttl');
        $this->validateGrants($issues);
        $this->validatePkce($issues);
        $this->validateResourceAudiences($issues);
        $this->validateScopePermissions($issues);
        $this->validateSigning($issues);
        $this->validateRoutes($issues);
        $this->validateRateLimits($issues);
        $this->validateRateLimitStore($issues, $production);
        $this->validateDatabase($issues);

        return $issues;
    }

    /** @param list<ConfigIssue> $issues */
    private function validateActiveSigningKeyId(array &$issues): ?string
    {
        $activeKeyId = $this->config->get('auth.oauth.signing.active_key_id');
        if (is_string($activeKeyId) && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $activeKeyId) === 1) {
            return $activeKeyId;
        }

        $issues[] = new ConfigIssue(
            'auth.oauth.signing.active_key_id must be a Base64URL-safe key id.',
            'auth.oauth.signing.active_key_id',
        );

        return null;
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
        if (!is_array($grants) || $grants === [] || !array_is_list($grants)) {
            $issues[] = new ConfigIssue('auth.oauth.grants must be a non-empty list.', 'auth.oauth.grants');

            return;
        }

        $seen = [];
        foreach ($grants as $grant) {
            if (!is_string($grant) || OAuthGrantType::tryFrom($grant) === null || isset($seen[$grant])) {
                $issues[] = new ConfigIssue(
                    'auth.oauth.grants contains an unsupported or duplicate grant type.',
                    'auth.oauth.grants',
                );

                return;
            }
            $seen[$grant] = true;
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
        if (!is_array($methods) || $methods !== ['S256']) {
            $issues[] = new ConfigIssue(
                'auth.oauth.pkce_methods must be exactly ["S256"] in Foundation 2.1.',
                'auth.oauth.pkce_methods',
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validatePositiveInteger(array &$issues, string $key, ?int $maximum = null): void
    {
        $value = $this->config->get($key);
        if (is_int($value) && $value > 0 && ($maximum === null || $value <= $maximum)) {
            return;
        }

        $message = $maximum === null
            ? sprintf('%s must be a positive integer.', $key)
            : sprintf('%s must be a positive integer no greater than %d.', $key, $maximum);
        $issues[] = new ConfigIssue($message, $key);
    }

    /** @param list<ConfigIssue> $issues */
    private function validatePrivateSigningKey(array &$issues): void
    {
        $privateKey = $this->config->get('auth.oauth.signing.private_key');
        if (!is_string($privateKey) || trim($privateKey) === '') {
            $issues[] = new ConfigIssue(
                'auth.oauth.signing.private_key must contain a deployment-owned key locator.',
                'auth.oauth.signing.private_key',
            );
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     * @return array{id:string,status:string}|null
     */
    private function validatePublicSigningKeyEntry(array &$issues, mixed $entry): ?array
    {
        if (!is_array($entry)) {
            $issues[] = new ConfigIssue('OAuth public-key entries must be maps.', 'auth.oauth.signing.public_keys');

            return null;
        }

        $id = $entry['id'] ?? null;
        $path = $entry['path'] ?? null;
        $status = $entry['status'] ?? null;
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1) {
            $issues[] = new ConfigIssue(
                'OAuth public-key ids must be unique Base64URL-safe values.',
                'auth.oauth.signing.public_keys',
            );

            return null;
        }
        if (!is_string($path) || trim($path) === '') {
            $issues[] = new ConfigIssue(
                'OAuth public-key entries require a deployment-owned path locator.',
                'auth.oauth.signing.public_keys',
            );

            return null;
        }
        if (!is_string($status) || !in_array($status, ['active', 'fallback'], true)) {
            $issues[] = new ConfigIssue(
                'OAuth public-key status must be active or fallback.',
                'auth.oauth.signing.public_keys',
            );

            return null;
        }
        if (!$this->validSigningKeyWindow($issues, $entry)) {
            return null;
        }

        return ['id' => $id, 'status' => $status];
    }

    /** @param list<ConfigIssue> $issues */
    private function validatePublicSigningKeys(array &$issues, ?string $activeKeyId): void
    {
        $publicKeys = $this->config->get('auth.oauth.signing.public_keys', []);
        if (!is_array($publicKeys) || $publicKeys === [] || !array_is_list($publicKeys)) {
            $issues[] = new ConfigIssue(
                'auth.oauth.signing.public_keys must be a non-empty list of public-key locators.',
                'auth.oauth.signing.public_keys',
            );

            return;
        }

        $seen = [];
        $activeCount = 0;
        foreach ($publicKeys as $entry) {
            $validated = $this->validatePublicSigningKeyEntry($issues, $entry);
            if ($validated === null) {
                return;
            }
            if (isset($seen[$validated['id']])) {
                $issues[] = new ConfigIssue(
                    'OAuth public-key ids must be unique Base64URL-safe values.',
                    'auth.oauth.signing.public_keys',
                );

                return;
            }
            $seen[$validated['id']] = true;
            if ($validated['status'] === 'active') {
                $activeCount++;
                if ($activeKeyId === null || !hash_equals($activeKeyId, $validated['id'])) {
                    $issues[] = new ConfigIssue(
                        'OAuth active public key must match active_key_id.',
                        'auth.oauth.signing.public_keys',
                    );
                }
            }
        }

        if ($activeCount !== 1) {
            $issues[] = new ConfigIssue(
                'auth.oauth.signing.public_keys must contain exactly one active key.',
                'auth.oauth.signing.public_keys',
            );
        }
        if ($activeKeyId !== null && !isset($seen[$activeKeyId])) {
            $issues[] = new ConfigIssue(
                'auth.oauth.signing.active_key_id must reference a configured public key.',
                'auth.oauth.signing.active_key_id',
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateRateLimits(array &$issues): void
    {
        $limits = $this->config->get('auth.oauth.rate_limits');
        if (!is_array($limits) || array_is_list($limits)) {
            $issues[] = new ConfigIssue(
                'auth.oauth.rate_limits must be a map of endpoint policies.',
                'auth.oauth.rate_limits',
            );

            return;
        }

        foreach (['authorization', 'token', 'revocation', 'introspection'] as $endpoint) {
            $policy = $limits[$endpoint] ?? null;
            $key = 'auth.oauth.rate_limits.' . $endpoint;
            if (!is_array($policy) || array_is_list($policy)) {
                $issues[] = new ConfigIssue(sprintf('%s must define max and window.', $key), $key);

                continue;
            }

            foreach (['max', 'window'] as $field) {
                $value = $policy[$field] ?? null;
                if (!is_int($value) || $value < 1) {
                    $issues[] = new ConfigIssue(
                        sprintf('%s.%s must be a positive integer.', $key, $field),
                        $key . '.' . $field,
                    );
                }
            }
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateRateLimitStore(array &$issues, bool $production): void
    {
        $store = $this->config->get('auth.oauth.rate_limit_store');
        if ($store !== null && (!is_string($store) || trim($store) === '')) {
            $issues[] = new ConfigIssue(
                'auth.oauth.rate_limit_store must be null or a non-empty cache store name.',
                'auth.oauth.rate_limit_store',
            );

            return;
        }
        if (!$production) {
            return;
        }

        try {
            new SharedStateTopology($this->config)->assertCacheStore(
                is_string($store) ? trim($store) : null,
                'OAuth endpoint rate limiting',
            );
        } catch (ConfigurationException $exception) {
            $issues[] = new ConfigIssue($exception->getMessage(), 'auth.oauth.rate_limit_store');
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateResourceAudiences(array &$issues): void
    {
        $audiences = $this->config->get('auth.oauth.resource_audiences', []);
        if (!is_array($audiences) || !array_is_list($audiences) || count($audiences) > 16) {
            $issues[] = new ConfigIssue(
                'auth.oauth.resource_audiences must be a list containing at most 16 audience identifiers.',
                'auth.oauth.resource_audiences',
            );

            return;
        }

        $seen = [];
        foreach ($audiences as $audience) {
            if (!is_string($audience) || trim($audience) === '' || strlen($audience) > 2048 || isset($seen[$audience])) {
                $issues[] = new ConfigIssue(
                    'auth.oauth.resource_audiences must contain unique non-empty strings no longer than 2048 bytes.',
                    'auth.oauth.resource_audiences',
                );

                return;
            }
            $seen[$audience] = true;
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateRoutes(array &$issues): void
    {
        $seen = [];
        foreach (['authorization', 'token', 'revocation', 'introspection', 'jwks'] as $name) {
            $key = 'auth.oauth.routes.' . $name;
            $path = $this->config->get($key);
            if (!is_string($path) || !$this->validRoutePath($path)) {
                $issues[] = new ConfigIssue(sprintf('%s must be a local absolute path.', $key), $key);

                continue;
            }

            if (isset($seen[$path])) {
                $issues[] = new ConfigIssue('OAuth protocol route paths must be unique.', $key);

                continue;
            }
            $seen[$path] = true;
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateScopePermissions(array &$issues): void
    {
        $mapping = $this->config->get('auth.oauth.scope_permissions', []);
        if (!is_array($mapping) || (array_is_list($mapping) && $mapping !== [])) {
            $issues[] = new ConfigIssue(
                'auth.oauth.scope_permissions must be a map of OAuth scope names to Foundation permission names.',
                'auth.oauth.scope_permissions',
            );

            return;
        }

        foreach ($mapping as $scope => $permission) {
            if (
                !is_string($scope)
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $scope) !== 1
                || !is_string($permission)
                || trim($permission) === ''
                || strlen($permission) > 255
            ) {
                $issues[] = new ConfigIssue(
                    'auth.oauth.scope_permissions contains an invalid scope or permission name.',
                    'auth.oauth.scope_permissions',
                );

                return;
            }
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateSigning(array &$issues): void
    {
        $this->validateSigningAlgorithm($issues);
        $activeKeyId = $this->validateActiveSigningKeyId($issues);
        $this->validatePrivateSigningKey($issues);
        $this->validatePublicSigningKeys($issues, $activeKeyId);
    }

    /** @param list<ConfigIssue> $issues */
    private function validateSigningAlgorithm(array &$issues): void
    {
        $algorithm = $this->config->get('auth.oauth.signing.algorithm', 'RS256');
        if (!is_string($algorithm) || !in_array($algorithm, self::SIGNING_ALGORITHMS, true)) {
            $issues[] = new ConfigIssue(
                'auth.oauth.signing.algorithm must select a supported asymmetric JWT algorithm.',
                'auth.oauth.signing.algorithm',
            );
        }
    }

    private function validRoutePath(string $path): bool
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        $parts = parse_url($path);

        return is_array($parts) && !isset($parts['scheme'], $parts['host'], $parts['query'], $parts['fragment']);
    }

    /**
     * @param list<ConfigIssue> $issues
     * @param array<string|int, mixed> $entry
     */
    private function validSigningKeyWindow(array &$issues, array $entry): bool
    {
        $notBefore = $entry['not_before'] ?? null;
        $notAfter = $entry['not_after'] ?? null;
        if ($notBefore !== null && (!is_int($notBefore) || $notBefore <= 0)) {
            $issues[] = new ConfigIssue(
                'OAuth public-key not_before must be a positive Unix timestamp.',
                'auth.oauth.signing.public_keys',
            );

            return false;
        }
        if ($notAfter !== null && (!is_int($notAfter) || $notAfter <= 0)) {
            $issues[] = new ConfigIssue(
                'OAuth public-key not_after must be a positive Unix timestamp.',
                'auth.oauth.signing.public_keys',
            );

            return false;
        }
        if (is_int($notBefore) && is_int($notAfter) && $notBefore >= $notAfter) {
            $issues[] = new ConfigIssue(
                'OAuth public-key validity must satisfy not_before < not_after.',
                'auth.oauth.signing.public_keys',
            );

            return false;
        }

        return true;
    }
}
