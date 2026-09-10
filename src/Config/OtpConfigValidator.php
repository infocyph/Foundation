<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\Foundation\Exception\ConfigurationException;

/**
 * Validate only Foundation's application policy around OTP 6.1.
 *
 * Algorithm parsing, secret decoding, OCRA suite parsing and verification
 * semantics remain OTP package responsibilities.
 */
final readonly class OtpConfigValidator
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    /** @return list<ConfigIssue> */
    public function validate(bool $assumeProduction = false): array
    {
        $issues = [];

        $issuer = $this->config->get('auth.otp.issuer', 'Foundation');
        if (!is_string($issuer) || trim($issuer) === '') {
            $issues[] = new ConfigIssue('auth.otp.issuer must be a non-empty string.', 'auth.otp.issuer');
        }

        $algorithm = $this->config->get('auth.otp.totp.algorithm', 'sha1');
        if (!is_string($algorithm) || !in_array(strtolower(trim($algorithm)), ['sha1', 'sha256', 'sha512'], true)) {
            $issues[] = new ConfigIssue(
                'auth.otp.totp.algorithm must be one of: sha1, sha256, sha512.',
                'auth.otp.totp.algorithm',
            );
        }

        $this->range($issues, 'auth.otp.totp.digits', 6, 9, 6);
        $this->range($issues, 'auth.otp.totp.period', 1, 86400, 30);
        $this->range($issues, 'auth.otp.totp.secret_bytes', 16, 1024, 20);
        $this->range($issues, 'auth.otp.totp.window', 0, 50, 1);
        $this->range($issues, 'auth.otp.hotp.look_ahead', 0, 100, 5);
        $this->range($issues, 'auth.otp.replay.ttl', 1, PHP_INT_MAX, 90);
        $this->range($issues, 'auth.otp.recovery_codes.count', 1, 100, 10);
        $this->range($issues, 'auth.otp.recovery_codes.length', 8, 128, 12);

        $production = $assumeProduction || $this->config->isProduction();
        $this->validateKeyPolicy($issues, $production);

        $store = $this->config->get('auth.otp.replay.store');
        if ($store !== null && (!is_string($store) || trim($store) === '')) {
            $issues[] = new ConfigIssue(
                'auth.otp.replay.store must be null or a non-empty configured cache store name.',
                'auth.otp.replay.store',
            );
        }
        if (is_string($store) && trim($store) !== '' && !$this->config->has('cache.stores.' . trim($store))) {
            $issues[] = new ConfigIssue(
                sprintf('auth.otp.replay.store references missing cache store "%s".', trim($store)),
                'auth.otp.replay.store',
            );
        }

        if ($production) {
            $this->validateReplayTopology($issues, is_string($store) && trim($store) !== '' ? trim($store) : null);
        }

        return $issues;
    }

    private function environmentName(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[A-Z][A-Z0-9_]{1,127}\z/D', $value) === 1;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9]\d*)$/D', $value) !== 1) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($validated) ? $validated : null;
    }

    private function positiveTimestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $resolved = $this->integer($value);

        return $resolved !== null && $resolved > 0 ? $resolved : null;
    }

    /** @param list<ConfigIssue> $issues */
    private function range(array &$issues, string $key, int $minimum, int $maximum, int $default): void
    {
        $value = $this->config->get($key, $default);
        $resolved = $this->integer($value);
        if ($resolved === null || $resolved < $minimum || $resolved > $maximum) {
            $issues[] = new ConfigIssue(
                sprintf('%s must be an integer between %d and %d.', $key, $minimum, $maximum),
                $key,
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateKeyDefinition(array &$issues, mixed $definition, int $index, int &$active): void
    {
        $path = sprintf('auth.otp.secret_protection.keys.%d', $index);
        if (!is_array($definition)) {
            $issues[] = new ConfigIssue($path . ' must be an array.', $path);

            return;
        }

        $id = $definition['id'] ?? null;
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1) {
            $issues[] = new ConfigIssue($path . '.id must be a Base64URL-safe identifier.', $path . '.id');
        }
        if (!$this->environmentName($definition['environment'] ?? null)) {
            $issues[] = new ConfigIssue(
                $path . '.environment must be an uppercase shell-variable name.',
                $path . '.environment',
            );
        }

        $status = $definition['status'] ?? null;
        if (!in_array($status, ['active', 'fallback', 'disabled', 'retired'], true)) {
            $issues[] = new ConfigIssue(
                $path . '.status must be active, fallback, disabled, or retired.',
                $path . '.status',
            );
        } elseif ($status === 'active') {
            $active++;
        }

        $notBefore = $this->positiveTimestamp($definition['not_before'] ?? null);
        $notAfter = $this->positiveTimestamp($definition['not_after'] ?? null);
        if (($definition['not_before'] ?? null) !== null && $notBefore === null) {
            $issues[] = new ConfigIssue($path . '.not_before must be a positive integer.', $path . '.not_before');
        }
        if (($definition['not_after'] ?? null) !== null && $notAfter === null) {
            $issues[] = new ConfigIssue($path . '.not_after must be a positive integer.', $path . '.not_after');
        }
        if ($notBefore !== null && $notAfter !== null && $notBefore >= $notAfter) {
            $issues[] = new ConfigIssue(
                $path . ' validity must satisfy not_before < not_after.',
                $path,
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateKeyPolicy(array &$issues, bool $production): void
    {
        $recoveryEnvironment = $this->config->get(
            'auth.otp.recovery_codes.hmac_key_environment',
            'AUTH_OTP_RECOVERY_HMAC_KEY',
        );
        if (!$this->environmentName($recoveryEnvironment)) {
            $issues[] = new ConfigIssue(
                'auth.otp.recovery_codes.hmac_key_environment must be an uppercase shell-variable name.',
                'auth.otp.recovery_codes.hmac_key_environment',
            );
        }

        $allowLegacy = $this->config->get('auth.otp.secret_protection.allow_legacy_plaintext', false);
        if (!is_bool($allowLegacy)) {
            $issues[] = new ConfigIssue(
                'auth.otp.secret_protection.allow_legacy_plaintext must be boolean.',
                'auth.otp.secret_protection.allow_legacy_plaintext',
            );
        }

        $keys = $this->config->get('auth.otp.secret_protection.keys', []);
        if (!is_array($keys) || !array_is_list($keys)) {
            $issues[] = new ConfigIssue(
                'auth.otp.secret_protection.keys must be a list.',
                'auth.otp.secret_protection.keys',
            );

            return;
        }
        if ($production && $keys === []) {
            $issues[] = new ConfigIssue(
                'Production OTP requires at least one explicit MFA secret-protection key locator.',
                'auth.otp.secret_protection.keys',
            );

            return;
        }

        $active = 0;
        foreach ($keys as $index => $definition) {
            $this->validateKeyDefinition($issues, $definition, $index, $active);
        }
        if ($keys !== [] && $active !== 1) {
            $issues[] = new ConfigIssue(
                'auth.otp.secret_protection.keys must contain exactly one active key.',
                'auth.otp.secret_protection.keys',
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateReplayTopology(array &$issues, ?string $store): void
    {
        try {
            $topology = new SharedStateTopology($this->config);
            $topology->assertCacheStore(
                $store,
                'OTP replay protection',
                $topology->requiredSecurityScope(),
                true,
            );
        } catch (ConfigurationException $exception) {
            $issues[] = new ConfigIssue($exception->getMessage(), 'auth.otp.replay.store');
        }
    }
}
