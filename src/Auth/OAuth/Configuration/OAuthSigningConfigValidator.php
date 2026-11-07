<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Configuration;

use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;

/** Foundation-owned signing-key locator and publication policy; Epicrypt owns JOSE primitives. */
final readonly class OAuthSigningConfigValidator
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
        $issues = [];
        $this->validateSigningAlgorithm($issues);
        $activeKeyId = $this->validateActiveSigningKeyId($issues);
        $this->validatePrivateSigningKey($issues);
        $this->validatePublicSigningKeys($issues, $activeKeyId);

        return $issues;
    }

    /** @param list<ConfigIssue> $issues */
    private function validateActivePublicKey(array &$issues, ?string $activeKeyId, string $keyId): void
    {
        if ($activeKeyId === null || !hash_equals($activeKeyId, $keyId)) {
            $issues[] = new ConfigIssue(
                'OAuth active public key must match active_key_id.',
                'auth.oauth.signing.public_keys',
            );
        }
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
                $this->validateActivePublicKey($issues, $activeKeyId, $validated['id']);
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
