<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\ArrayKit\Config\Support\Environment;
use Infocyph\Foundation\Auth\Driver\AuthCacheDriver;
use Infocyph\Foundation\Auth\Driver\AuthMfaDriver;
use Infocyph\Foundation\Auth\Driver\AuthNotificationDriver;
use Infocyph\Foundation\Auth\Driver\AuthPasskeyDriver;
use Infocyph\Foundation\Auth\Driver\AuthPasswordDriver;
use Infocyph\Foundation\Auth\Driver\AuthStorageDriver;
use Infocyph\Foundation\Auth\Driver\AuthTokenDriver;
use Infocyph\Foundation\Config\Internal\CacheTopologyValidator;

final readonly class ConfigValidator
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function validate(): ConfigValidationResult
    {
        return $this->runChecks($this->config->isProduction());
    }

    public function validateForProduction(): ConfigValidationResult
    {
        return $this->runChecks(true);
    }

    private function databaseDefault(): ?string
    {
        $configured = $this->config->get('database.default');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $connections = $this->config->get('database.connections', []);
        if (!is_array($connections) || $connections === []) {
            return null;
        }

        $first = array_key_first($connections);

        return is_string($first) && $first !== '' ? $first : null;
    }

    private function isLocalWebAuthnHost(mixed $host): bool
    {
        if (!is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), ['localhost', '127.0.0.1'], true);
    }

    private function isNonNegativeInteger(mixed $value): bool
    {
        return (is_int($value) && $value >= 0)
            || (is_string($value) && preg_match('/^(?:0|[1-9]\d*)$/D', $value) === 1);
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1);
    }

    /** @return array<string,mixed>|null */
    private function notificationSenderProfile(): ?array
    {
        $sender = $this->config->get('notifications.auth.sender');
        if (!is_string($sender) || trim($sender) === '') {
            $sender = $this->config->get('notifications.email.default_sender', 'default');
        }
        if (!is_string($sender) || trim($sender) === '') {
            return null;
        }

        $profile = $this->config->get('notifications.email.senders.' . trim($sender));
        if (!is_array($profile)) {
            return null;
        }

        $normalized = [];
        foreach ($profile as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function resolvedTokenSecret(): ?string
    {
        $configured = $this->config->get('auth.token_secret');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $environment = Environment::get('AUTH_TOKEN_SECRET');

        return is_string($environment) && $environment !== '' ? $environment : null;
    }

    private function runChecks(bool $assumeProduction): ConfigValidationResult
    {
        $issues = [];

        $this->validateDriver($issues, 'auth.drivers.cache', $this->stringConfig('auth.drivers.cache', 'array'), AuthCacheDriver::class);
        $this->validateDriver($issues, 'auth.drivers.mfa', $this->stringConfig('auth.drivers.mfa', 'simple'), AuthMfaDriver::class);
        $this->validateDriver($issues, 'auth.drivers.notifications', $this->stringConfig('auth.drivers.notifications', 'collect'), AuthNotificationDriver::class);
        $this->validateDriver($issues, 'auth.drivers.passkey', $this->stringConfig('auth.drivers.passkey', 'memory'), AuthPasskeyDriver::class);
        $this->validateDriver($issues, 'auth.drivers.passwords', $this->stringConfig('auth.drivers.passwords', 'native'), AuthPasswordDriver::class);
        $this->validateDriver($issues, 'auth.drivers.storage', $this->stringConfig('auth.drivers.storage', 'memory'), AuthStorageDriver::class);
        $this->validateDriver($issues, 'auth.drivers.tokens', $this->stringConfig('auth.drivers.tokens', 'simple'), AuthTokenDriver::class);
        $issues = [...$issues, ...new RuntimeConfigValidator($this->config)->validate()];

        $storageDriver = $this->stringConfig('auth.drivers.storage', 'memory');
        $cacheDriver = $this->stringConfig('auth.drivers.cache', 'array');
        $notificationDriver = $this->stringConfig('auth.drivers.notifications', 'collect');
        $passkeyDriver = $this->stringConfig('auth.drivers.passkey', 'memory');
        $tokenDriver = $this->stringConfig('auth.drivers.tokens', 'simple');

        if ($assumeProduction) {
            $this->validateProductionDrivers($issues, $storageDriver);
        }

        if ($tokenDriver === AuthTokenDriver::SECURITY->value) {
            $this->validateSecurityTokenPolicy($issues, $assumeProduction);
        } elseif ($assumeProduction) {
            $this->validateTokenSecret($issues, 32);
        }

        if ($storageDriver === AuthStorageDriver::DATABASE->value) {
            $this->validateDatabaseStorage($issues);
        }

        if ($cacheDriver === AuthCacheDriver::CACHE->value) {
            $this->validateCacheStore($issues);
        }

        $issues = [...$issues, ...new CacheTopologyValidator($this->config)->validate()];

        if ($notificationDriver === AuthNotificationDriver::TALKINGBYTES->value) {
            $this->validateNotificationSender($issues, $assumeProduction);
        }

        if ($passkeyDriver === AuthPasskeyDriver::WEBAUTHN->value) {
            $this->validateWebAuthn($issues, $assumeProduction);
        }

        return new ConfigValidationResult($issues);
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @param list<ConfigIssue> $issues
     * @param list<string> $allowed
     */
    private function validateAllowedString(array &$issues, string $key, mixed $value, array $allowed): void
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $issues[] = new ConfigIssue(
                sprintf('%s must be one of: %s.', $key, implode(', ', $allowed)),
                $key,
            );
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     * @param list<string> $allowed
     */
    private function validateAllowedStringList(array &$issues, string $key, mixed $value, array $allowed): void
    {
        if (!is_array($value) || $value === []) {
            $issues[] = new ConfigIssue(
                sprintf('%s must be a non-empty list of: %s.', $key, implode(', ', $allowed)),
                $key,
            );

            return;
        }

        foreach ($value as $item) {
            if (is_string($item) && in_array($item, $allowed, true)) {
                continue;
            }

            $issues[] = new ConfigIssue(
                sprintf('%s contains unsupported value. Allowed values: %s.', $key, implode(', ', $allowed)),
                $key,
            );

            return;
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateCacheStore(array &$issues): void
    {
        $store = $this->stringConfig('cache.default', '');

        if ($store === '') {
            $issues[] = new ConfigIssue(
                'cache.default must be configured when auth.drivers.cache uses cache.',
                'cache.default',
            );

            return;
        }

        if (!$this->config->has('cache.stores.' . $store)) {
            $issues[] = new ConfigIssue(
                sprintf('cache.stores.%s must exist when auth.drivers.cache uses cache.', $store),
                'cache.stores.' . $store,
            );
        }

        $counter = $this->stringConfig('cache.default_counter', '');
        if ($counter !== '' && !$this->config->has('cache.counters.' . $counter)) {
            $issues[] = new ConfigIssue(
                sprintf('cache.counters.%s must exist when cache.default_counter is configured.', $counter),
                'cache.counters.' . $counter,
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateDatabaseStorage(array &$issues): void
    {
        $connectionName = $this->databaseDefault();
        if ($connectionName === null) {
            $issues[] = new ConfigIssue(
                'database.default must be configured when auth.drivers.storage uses database.',
                'database.default',
            );

            return;
        }

        $connection = $this->config->get('database.connections.' . $connectionName);
        if (!is_array($connection) || $connection === []) {
            $issues[] = new ConfigIssue(
                sprintf('database.connections.%s must exist when auth.drivers.storage uses database.', $connectionName),
                'database.connections.' . $connectionName,
            );
        }
    }

    /** @param list<ConfigIssue> $issues @param class-string<\BackedEnum> $enumClass */
    private function validateDriver(array &$issues, string $key, string $value, string $enumClass): void
    {
        if ($enumClass::tryFrom($value) !== null) {
            return;
        }

        $issues[] = new ConfigIssue(
            sprintf('Invalid driver "%s" configured for %s.', $value, $key),
            $key,
        );
    }

    /** @param list<ConfigIssue> $issues */
    private function validateNotificationSender(array &$issues, bool $assumeProduction): void
    {
        $profile = $this->notificationSenderProfile();
        if ($profile === null) {
            $issues[] = new ConfigIssue(
                'notifications.auth.sender must reference a configured notifications.email.senders profile.',
                'notifications.auth.sender',
            );

            return;
        }

        $transport = $profile['transport'] ?? null;
        if (!is_string($transport) || trim($transport) === '') {
            $issues[] = new ConfigIssue(
                'The auth email sender must select a configured transport.',
                'notifications.auth.sender',
            );

            return;
        }

        $transportConfig = $this->config->get('notifications.email.transports.' . trim($transport));
        if (!is_array($transportConfig)) {
            $issues[] = new ConfigIssue(
                sprintf('Email transport "%s" selected by the auth sender is not configured.', $transport),
                'notifications.email.transports.' . trim($transport),
            );

            return;
        }

        $driver = $transportConfig['driver'] ?? $transport;
        if (!is_string($driver) || !in_array($driver, ['fake', 'log', 'mail', 'null', 'sendmail', 'smtp', 'spool'], true)) {
            $issues[] = new ConfigIssue(
                sprintf('Email transport "%s" uses an unsupported driver.', $transport),
                'notifications.email.transports.' . trim($transport) . '.driver',
            );

            return;
        }

        if ($assumeProduction && in_array($driver, ['fake', 'null'], true)) {
            $issues[] = new ConfigIssue(
                sprintf('The TalkingBytes auth sender must not use the "%s" transport driver in production.', $driver),
                'notifications.auth.sender',
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateProductionDrivers(array &$issues, string $storageDriver): void
    {
        if ($this->stringConfig('auth.drivers.tokens', 'simple') === AuthTokenDriver::SIMPLE->value) {
            $issues[] = new ConfigIssue('auth.drivers.tokens uses simple.', 'auth.drivers.tokens');
        }

        if ($storageDriver === AuthStorageDriver::MEMORY->value) {
            $issues[] = new ConfigIssue('auth.drivers.storage uses memory.', 'auth.drivers.storage');
        }

        if ($this->stringConfig('auth.drivers.mfa', 'simple') === AuthMfaDriver::SIMPLE->value) {
            $issues[] = new ConfigIssue('auth.drivers.mfa uses simple.', 'auth.drivers.mfa');
        }

        if ($this->stringConfig('auth.drivers.notifications', 'collect') === AuthNotificationDriver::COLLECT->value) {
            $issues[] = new ConfigIssue('auth.drivers.notifications uses collect.', 'auth.drivers.notifications');
        }

        if ($this->stringConfig('auth.drivers.passkey', 'memory') === AuthPasskeyDriver::MEMORY->value) {
            $issues[] = new ConfigIssue('auth.drivers.passkey uses memory.', 'auth.drivers.passkey');
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateSecurityTokenPolicy(array &$issues, bool $assumeProduction): void
    {
        $algorithm = $this->config->get('security.jwt.algorithm', 'HS256');
        $normalized = is_string($algorithm) ? strtoupper(trim($algorithm)) : '';
        $minimumBytes = match ($normalized) {
            'HS256' => 32,
            'HS384' => 48,
            'HS512' => 64,
            default => 0,
        };
        if ($minimumBytes === 0) {
            $issues[] = new ConfigIssue(
                'security.jwt.algorithm must be one of: HS256, HS384, HS512.',
                'security.jwt.algorithm',
            );
        }

        foreach (['issuer', 'audience'] as $key) {
            $value = $this->config->get('security.jwt.' . $key);
            if (!is_string($value) || trim($value) === '') {
                $issues[] = new ConfigIssue(
                    sprintf('security.jwt.%s must be configured when auth.drivers.tokens uses security.', $key),
                    'security.jwt.' . $key,
                );
            }
        }

        $maximumLifetime = $this->config->get('security.jwt.maximum_lifetime_seconds', 1209600);
        if (!$this->isPositiveInteger($maximumLifetime)) {
            $issues[] = new ConfigIssue(
                'security.jwt.maximum_lifetime_seconds must be a positive integer.',
                'security.jwt.maximum_lifetime_seconds',
            );
        }

        $leeway = $this->config->get('security.jwt.leeway_seconds', 0);
        if (!$this->isNonNegativeInteger($leeway)) {
            $issues[] = new ConfigIssue(
                'security.jwt.leeway_seconds must be a non-negative integer.',
                'security.jwt.leeway_seconds',
            );
        }

        if ($minimumBytes > 0) {
            $this->validateTokenSecret($issues, $minimumBytes, $assumeProduction);
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateTokenSecret(array &$issues, int $minimumBytes, bool $required = true): void
    {
        $secret = $this->resolvedTokenSecret();
        if ($secret === null) {
            if ($required) {
                $issues[] = new ConfigIssue(
                    'AUTH_TOKEN_SECRET or auth.token_secret must be configured for the selected production token policy.',
                    'auth.token_secret',
                );
            }

            return;
        }

        if (in_array($secret, [
            'foundation-dev-secret',
            'foundation-development-token-secret-change-me',
            'foundation-development-token-secret-change-me-000000000000000000000000',
        ], true)) {
            $issues[] = new ConfigIssue('The authentication token secret must not use a development placeholder.', 'auth.token_secret');

            return;
        }

        if (strlen($secret) < $minimumBytes) {
            $issues[] = new ConfigIssue(
                sprintf('Authentication token secret must be at least %d bytes for the selected token policy.', $minimumBytes),
                'auth.token_secret',
            );
        }
    }

    /** @param list<ConfigIssue> $issues */
    private function validateWebAuthn(array &$issues, bool $assumeProduction): void
    {
        $rpId = $this->config->get('auth.webauthn.rp_id');
        $origin = $this->config->get('auth.webauthn.origin');
        $attestation = $this->config->get('auth.webauthn.attestation', 'none');
        $userVerification = $this->config->get('auth.webauthn.user_verification', 'preferred');
        $residentKey = $this->config->get('auth.webauthn.resident_key', 'preferred');
        $algorithms = $this->config->get('auth.webauthn.algorithms', ['ES256', 'RS256']);
        $transports = $this->config->get('auth.webauthn.transports', ['internal', 'hybrid', 'usb', 'nfc', 'ble']);

        if (!is_string($rpId) || $rpId === '') {
            $issues[] = new ConfigIssue(
                'auth.webauthn.rp_id must be configured when auth.drivers.passkey uses webauthn.',
                'auth.webauthn.rp_id',
            );
        }

        if (!is_string($origin) || $origin === '') {
            $issues[] = new ConfigIssue(
                'auth.webauthn.origin must be configured when auth.drivers.passkey uses webauthn.',
                'auth.webauthn.origin',
            );

            return;
        }

        $scheme = parse_url($origin, PHP_URL_SCHEME);
        $host = parse_url($origin, PHP_URL_HOST);

        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            $issues[] = new ConfigIssue(
                'auth.webauthn.origin must be a valid http or https origin.',
                'auth.webauthn.origin',
            );

            return;
        }

        if ($assumeProduction && strtolower($scheme) !== 'https' && !$this->isLocalWebAuthnHost($host)) {
            $issues[] = new ConfigIssue(
                'auth.webauthn.origin must use https outside localhost/local development.',
                'auth.webauthn.origin',
            );
        }

        if (!is_string($attestation) || !in_array($attestation, ['none', 'direct', 'indirect', 'enterprise'], true)) {
            $issues[] = new ConfigIssue(
                'auth.webauthn.attestation must be one of: none, direct, indirect, enterprise.',
                'auth.webauthn.attestation',
            );
        }

        $this->validateAllowedString(
            $issues,
            'auth.webauthn.user_verification',
            $userVerification,
            ['required', 'preferred', 'discouraged'],
        );
        $this->validateAllowedString(
            $issues,
            'auth.webauthn.resident_key',
            $residentKey,
            ['required', 'preferred', 'discouraged'],
        );
        $this->validateAllowedStringList(
            $issues,
            'auth.webauthn.algorithms',
            $algorithms,
            ['ES256', 'RS256'],
        );
        $this->validateAllowedStringList(
            $issues,
            'auth.webauthn.transports',
            $transports,
            ['internal', 'hybrid', 'usb', 'nfc', 'ble'],
        );
    }
}
