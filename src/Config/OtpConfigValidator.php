<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

/**
 * Validate only Foundation's application policy around OTP 6.0.
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
    public function validate(): array
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

        return $issues;
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
}
