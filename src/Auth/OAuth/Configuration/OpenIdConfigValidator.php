<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Configuration;

use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class OpenIdConfigValidator
{
    private const array SIGNING_ALGORITHMS = [
        'RS256', 'RS384', 'RS512',
        'PS256', 'PS384', 'PS512',
        'ES256', 'ES384', 'ES512',
        'EdDSA',
    ];

    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function validate(): array
    {
        $enabled = $this->config->get('auth.oauth.oidc.enabled', false);
        if (!is_bool($enabled)) {
            return [new ConfigIssue('auth.oauth.oidc.enabled must be a boolean.', 'auth.oauth.oidc.enabled')];
        }
        if (!$enabled) {
            return [];
        }

        return [
            ...$this->validateLifetime(),
            ...$this->validateUserInfo(),
            ...$this->validateDiscoveryLists(),
            ...$this->validateSigning(),
        ];
    }

    /** @param list<ConfigIssue> $issues */
    private function activeKeyId(array &$issues): ?string
    {
        $activeId = $this->config->get('auth.oauth.oidc.signing.active_key_id');
        if (is_string($activeId) && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $activeId) === 1) {
            return $activeId;
        }

        $issues[] = new ConfigIssue(
            'auth.oauth.oidc.signing.active_key_id must be a Base64URL-safe key id.',
            'auth.oauth.oidc.signing.active_key_id',
        );

        return null;
    }

    /** @return list<string>|null */
    private function stringList(string $key): ?array
    {
        $values = $this->config->get($key);
        if (!is_array($values) || !array_is_list($values)) {
            return null;
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                return null;
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    /** @return list<ConfigIssue> */
    private function validateDiscoveryLists(): array
    {
        $issues = [];
        foreach ([
            'auth.oauth.oidc.scopes_supported' => 'openid',
            'auth.oauth.oidc.claims_supported' => 'sub',
        ] as $key => $required) {
            $values = $this->stringList($key);
            if ($values === null || !in_array($required, $values, true) || count($values) !== count(array_unique($values))) {
                $issues[] = new ConfigIssue(
                    sprintf('%s must be a unique non-empty string list containing %s.', $key, $required),
                    $key,
                );
            }
        }

        if ($this->config->get('auth.oauth.oidc.subject_types') !== ['public']) {
            $issues[] = new ConfigIssue(
                'Foundation currently exposes only the public OpenID subject type.',
                'auth.oauth.oidc.subject_types',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function validateLifetime(): array
    {
        $lifetime = $this->config->get('auth.oauth.oidc.id_token_lifetime_seconds');
        if (is_int($lifetime) && $lifetime >= 1 && $lifetime <= 3_600) {
            return [];
        }

        return [new ConfigIssue(
            'auth.oauth.oidc.id_token_lifetime_seconds must be between 1 and 3600.',
            'auth.oauth.oidc.id_token_lifetime_seconds',
        )];
    }

    /** @return list<ConfigIssue> */
    private function validatePublicKeys(?string $activeId): array
    {
        $public = $this->config->get('auth.oauth.oidc.signing.public_keys');
        if (!is_array($public) || $public === [] || !array_is_list($public)) {
            return [new ConfigIssue(
                'auth.oauth.oidc.signing.public_keys must be a non-empty list.',
                'auth.oauth.oidc.signing.public_keys',
            )];
        }

        $seen = [];
        $activeCount = 0;
        foreach ($public as $entry) {
            if (!$this->validPublicKeyEntry($entry, $seen, $activeId, $activeCount)) {
                return [new ConfigIssue(
                    'OpenID public signing-key entries are invalid.',
                    'auth.oauth.oidc.signing.public_keys',
                )];
            }
        }

        return $activeCount === 1
            ? []
            : [new ConfigIssue(
                'auth.oauth.oidc.signing.public_keys must contain exactly one active key.',
                'auth.oauth.oidc.signing.public_keys',
            )];
    }

    /** @return list<ConfigIssue> */
    private function validateSigning(): array
    {
        $issues = [];
        $algorithm = $this->config->get('auth.oauth.oidc.signing.algorithm');
        if (!is_string($algorithm) || !in_array($algorithm, self::SIGNING_ALGORITHMS, true)) {
            $issues[] = new ConfigIssue(
                'auth.oauth.oidc.signing.algorithm must select a supported asymmetric JWT algorithm.',
                'auth.oauth.oidc.signing.algorithm',
            );
        }

        $activeId = $this->activeKeyId($issues);
        $private = $this->config->get('auth.oauth.oidc.signing.private_key');
        if (!is_string($private) || trim($private) === '') {
            $issues[] = new ConfigIssue(
                'auth.oauth.oidc.signing.private_key must contain a deployment-owned key locator.',
                'auth.oauth.oidc.signing.private_key',
            );
        }

        return [...$issues, ...$this->validatePublicKeys($activeId)];
    }

    /** @return list<ConfigIssue> */
    private function validateUserInfo(): array
    {
        $issues = [];
        $route = $this->config->get('auth.oauth.oidc.userinfo_route');
        if (!is_string($route) || !$this->validRoutePath($route)) {
            $issues[] = new ConfigIssue(
                'auth.oauth.oidc.userinfo_route must be a local absolute path.',
                'auth.oauth.oidc.userinfo_route',
            );
        }

        $audience = $this->config->get('auth.oauth.oidc.userinfo_audience');
        if ($audience !== null && (!is_string($audience) || $audience === '' || strlen($audience) > 2_048)) {
            $issues[] = new ConfigIssue(
                'auth.oauth.oidc.userinfo_audience must be null or a bounded non-empty string.',
                'auth.oauth.oidc.userinfo_audience',
            );
        }

        return $issues;
    }

    /** @param array<string, true> $seen */
    private function validPublicKeyEntry(mixed $entry, array &$seen, ?string $activeId, int &$activeCount): bool
    {
        if (!is_array($entry)) {
            return false;
        }

        $id = $entry['id'] ?? null;
        $path = $entry['path'] ?? null;
        $status = $entry['status'] ?? null;
        if (!is_string($id)
            || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1
            || isset($seen[$id])
            || !is_string($path)
            || trim($path) === ''
            || !in_array($status, ['active', 'fallback'], true)
        ) {
            return false;
        }

        $seen[$id] = true;
        if ($status !== 'active') {
            return true;
        }

        ++$activeCount;

        return is_string($activeId) && hash_equals($activeId, $id);
    }

    private function validRoutePath(string $path): bool
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        $parts = parse_url($path);

        return is_array($parts) && !isset($parts['scheme'], $parts['host'], $parts['query'], $parts['fragment']);
    }
}
