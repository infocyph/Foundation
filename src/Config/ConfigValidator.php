<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\Foundation\Auth\Driver\AuthCacheDriver;
use Infocyph\Foundation\Auth\Driver\AuthMfaDriver;
use Infocyph\Foundation\Auth\Driver\AuthNotificationDriver;
use Infocyph\Foundation\Auth\Driver\AuthPasskeyDriver;
use Infocyph\Foundation\Auth\Driver\AuthPasswordDriver;
use Infocyph\Foundation\Auth\Driver\AuthStorageDriver;
use Infocyph\Foundation\Auth\Driver\AuthTokenDriver;

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

    private function runChecks(bool $assumeProduction): ConfigValidationResult
    {
        $issues = [];

        $this->validateDriver($issues, 'auth.drivers.cache', (string) $this->config->get('auth.drivers.cache', 'array'), AuthCacheDriver::class);
        $this->validateDriver($issues, 'auth.drivers.mfa', (string) $this->config->get('auth.drivers.mfa', 'simple'), AuthMfaDriver::class);
        $this->validateDriver($issues, 'auth.drivers.notifications', (string) $this->config->get('auth.drivers.notifications', 'collect'), AuthNotificationDriver::class);
        $this->validateDriver($issues, 'auth.drivers.passkey', (string) $this->config->get('auth.drivers.passkey', 'memory'), AuthPasskeyDriver::class);
        $this->validateDriver($issues, 'auth.drivers.passwords', (string) $this->config->get('auth.drivers.passwords', 'native'), AuthPasswordDriver::class);
        $this->validateDriver($issues, 'auth.drivers.storage', (string) $this->config->get('auth.drivers.storage', 'memory'), AuthStorageDriver::class);
        $this->validateDriver($issues, 'auth.drivers.tokens', (string) $this->config->get('auth.drivers.tokens', 'simple'), AuthTokenDriver::class);

        $storageDriver = (string) $this->config->get('auth.drivers.storage', 'memory');
        $cacheDriver = (string) $this->config->get('auth.drivers.cache', 'array');
        $notificationDriver = (string) $this->config->get('auth.drivers.notifications', 'collect');
        $passkeyDriver = (string) $this->config->get('auth.drivers.passkey', 'memory');

        if ($assumeProduction) {
            $this->validateProductionDrivers($issues, $storageDriver);
            $this->validateTokenSecret($issues);
        }

        if ($storageDriver === AuthStorageDriver::DBLAYER->value) {
            $this->validateDatabaseStorage($issues);
        }

        if ($cacheDriver === AuthCacheDriver::CACHELAYER->value) {
            $this->validateCacheStore($issues);
        }

        if ($notificationDriver === AuthNotificationDriver::TALKINGBYTES->value) {
            $this->validateNotificationTransport($issues);
        }

        if ($passkeyDriver === AuthPasskeyDriver::WEBAUTHN->value) {
            $this->validateWebAuthn($issues, $assumeProduction);
        }

        return new ConfigValidationResult($issues);
    }

    /**
     * @param list<ConfigIssue> $issues
     */
    private function validateCacheStore(array &$issues): void
    {
        $store = (string) $this->config->get(
            'auth.cachelayer.store',
            (string) $this->config->get('cache.default', ''),
        );

        if ($store === '') {
            $issues[] = new ConfigIssue(
                'auth.cachelayer.store must be configured when auth.drivers.cache uses cachelayer.',
                'auth.cachelayer.store',
            );

            return;
        }

        if (!$this->config->has('cache.stores.' . $store)) {
            $issues[] = new ConfigIssue(
                sprintf('cache.stores.%s must exist when auth.drivers.cache uses cachelayer.', $store),
                'cache.stores.' . $store,
            );
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     * @param class-string<\BackedEnum> $enumClass
     */
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

    /**
     * @param list<ConfigIssue> $issues
     */
    private function validateDatabaseStorage(array &$issues): void
    {
        $connectionName = $this->databaseDefault();
        if ($connectionName === null) {
            $issues[] = new ConfigIssue(
                'A database connection must be configured when auth.drivers.storage uses dblayer.',
                'auth.dblayer.connection',
            );

            return;
        }

        $connection = $this->config->get('database.connections.' . $connectionName);
        if (!is_array($connection) || $connection === []) {
            $issues[] = new ConfigIssue(
                sprintf('database.connections.%s must exist when auth.drivers.storage uses dblayer.', $connectionName),
                'database.connections.' . $connectionName,
            );
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     */
    private function validateNotificationTransport(array &$issues): void
    {
        $transport = $this->config->get('notifications.auth.transport');
        if (
            !is_string($transport)
            || $transport === ''
            || $transport === 'null'
            || $transport === 'replace-me'
        ) {
            $issues[] = new ConfigIssue(
                'notifications.auth.transport must be configured when auth.drivers.notifications uses talkingbytes.',
                'notifications.auth.transport',
            );
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     */
    private function validateProductionDrivers(array &$issues, string $storageDriver): void
    {
        if ((string) $this->config->get('auth.drivers.tokens', 'simple') === AuthTokenDriver::SIMPLE->value) {
            $issues[] = new ConfigIssue('auth.drivers.tokens uses simple.', 'auth.drivers.tokens');
        }

        if ($storageDriver === AuthStorageDriver::MEMORY->value) {
            $issues[] = new ConfigIssue('auth.drivers.storage uses memory.', 'auth.drivers.storage');
        }

        if ((string) $this->config->get('auth.drivers.mfa', 'simple') === AuthMfaDriver::SIMPLE->value) {
            $issues[] = new ConfigIssue('auth.drivers.mfa uses simple.', 'auth.drivers.mfa');
        }

        if ((string) $this->config->get('auth.drivers.notifications', 'collect') === AuthNotificationDriver::COLLECT->value) {
            $issues[] = new ConfigIssue('auth.drivers.notifications uses collect.', 'auth.drivers.notifications');
        }

        if ((string) $this->config->get('auth.drivers.passkey', 'memory') === AuthPasskeyDriver::MEMORY->value) {
            $issues[] = new ConfigIssue('auth.drivers.passkey uses memory.', 'auth.drivers.passkey');
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     */
    private function validateTokenSecret(array &$issues): void
    {
        $secret = $this->config->get('auth.token_secret');
        if (
            !is_string($secret)
            || $secret === ''
            || $secret === 'foundation-dev-secret'
            || $secret === 'replace-with-a-production-token-secret'
        ) {
            $issues[] = new ConfigIssue('auth.token_secret must be configured for production.', 'auth.token_secret');

            return;
        }

        if (strlen($secret) < 32) {
            $issues[] = new ConfigIssue('auth.token_secret must be at least 32 bytes for production.', 'auth.token_secret');
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     */
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

        if (!is_string($attestation) || $attestation !== 'none') {
            $issues[] = new ConfigIssue(
                'auth.webauthn.attestation must be "none" because other attestation modes are not supported yet.',
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

    private function isLocalWebAuthnHost(mixed $host): bool
    {
        if (!is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), ['localhost', '127.0.0.1'], true);
    }

    private function databaseDefault(): ?string
    {
        $configuredAuthConnection = $this->config->get('auth.dblayer.connection');
        if (is_string($configuredAuthConnection) && $configuredAuthConnection !== '') {
            return $configuredAuthConnection;
        }

        $configured = $this->config->get('database.default');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $connections = $this->config->get('database.connections', []);
        if (!is_array($connections) || $connections === []) {
            return null;
        }

        $first = array_key_first($connections);

        return is_string($first) && $first !== ''
            ? $first
            : null;
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
}
