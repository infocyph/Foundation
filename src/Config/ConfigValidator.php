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

    /**
     * @return array<string, mixed>
     */
    private function cacheDefinitions(string $key): array
    {
        $definitions = $this->config->get($key, []);
        if (!is_array($definitions)) {
            return [];
        }

        $named = [];
        foreach ($definitions as $name => $definition) {
            if (is_string($name)) {
                $named[$name] = $definition;
            }
        }

        return $named;
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

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function isLocalWebAuthnHost(mixed $host): bool
    {
        if (!is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), ['localhost', '127.0.0.1'], true);
    }

    private function isNodeCachePath(string $path): bool
    {
        $base = $this->stringConfig('app.base_path', getcwd() ?: '.');
        $cache = $this->config->get('paths.cache', 'storage/cache');
        $cache = is_string($cache) && $cache !== '' ? $cache : 'storage/cache';
        $directory = $this->isAbsolutePath($cache) ? $cache : $base . DIRECTORY_SEPARATOR . $cache;
        $file = $this->isAbsolutePath($path) ? $path : $base . DIRECTORY_SEPARATOR . $path;
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $directory . '/');
    }

    private function reservedCachePurpose(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower($value), ['auth', 'session', 'security', 'idempotency'], true);
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

        $storageDriver = $this->stringConfig('auth.drivers.storage', 'memory');
        $cacheDriver = $this->stringConfig('auth.drivers.cache', 'array');
        $notificationDriver = $this->stringConfig('auth.drivers.notifications', 'collect');
        $passkeyDriver = $this->stringConfig('auth.drivers.passkey', 'memory');

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

        $this->validateCacheLayerTopology($issues);

        if ($notificationDriver === AuthNotificationDriver::TALKINGBYTES->value) {
            $this->validateNotificationTransport($issues);
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

    /**
     * @param list<ConfigIssue> $issues
     * @param array<string, mixed> $clusters
     * @param array<string, mixed> $stores
     * @param array<string, mixed> $transports
     */
    private function validateCacheClusters(array &$issues, array $clusters, array $stores, array $transports): void
    {
        foreach ($clusters as $name => $cluster) {
            if (!is_array($cluster)) {
                continue;
            }

            $key = 'cache.clusters.' . $name;
            $store = $cluster['store'] ?? null;
            $transport = $cluster['transport'] ?? null;
            if (!is_string($cluster['node_id'] ?? null) || $cluster['node_id'] === '') {
                $issues[] = new ConfigIssue($key . '.node_id must be a stable explicit instance identity.', $key . '.node_id');
            }
            if (!is_string($store) || !isset($stores[$store]) || !is_array($stores[$store]) || ($stores[$store]['driver'] ?? null) !== 'node') {
                $issues[] = new ConfigIssue($key . '.store must reference a node cache store.', $key . '.store');
            }
            if (!is_string($transport) || !isset($transports[$transport]) || !is_array($transports[$transport])) {
                $issues[] = new ConfigIssue($key . '.transport must reference a configured shared transport.', $key . '.transport');
            }
            if ($this->reservedCachePurpose($name) || $this->reservedCachePurpose($cluster['purpose'] ?? null)) {
                $issues[] = new ConfigIssue(
                    $key . ' cannot be used for auth, session, security, or idempotency state.',
                    $key,
                );
            }
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     * @param array<string, mixed> $counters
     */
    private function validateCacheCounters(array &$issues, array $counters): void
    {
        foreach ($counters as $name => $counter) {
            if (!is_array($counter) || !in_array($counter['driver'] ?? null, ['redis', 'valkey'], true)) {
                $issues[] = new ConfigIssue(
                    sprintf('cache.counters.%s must use Redis or Valkey for atomic increments.', $name),
                    'cache.counters.' . $name,
                );
            }
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     */
    private function validateCacheLayerTopology(array &$issues): void
    {
        $stores = $this->cacheDefinitions('cache.stores');
        $clusters = $this->cacheDefinitions('cache.clusters');
        $transports = $this->cacheDefinitions('cache.transports');

        $this->validateNodeCacheStores($issues, $stores);
        $this->validateCacheClusters($issues, $clusters, $stores, $transports);
        $this->validateCacheTransports($issues, $transports);
        $this->validateCacheCounters($issues, $this->cacheDefinitions('cache.counters'));
    }

    /**
     * @param list<ConfigIssue> $issues
     */
    private function validateCacheStore(array &$issues): void
    {
        $store = $this->stringConfig(
            'auth.cachelayer.store',
            $this->stringConfig('cache.default', ''),
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

        $counter = $this->stringConfig('auth.cachelayer.counter', '');
        if ($counter !== '' && !$this->config->has('cache.counters.' . $counter)) {
            $issues[] = new ConfigIssue(
                sprintf('cache.counters.%s must exist when auth.cachelayer.counter is configured.', $counter),
                'cache.counters.' . $counter,
            );
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     * @param array<string, mixed> $transports
     */
    private function validateCacheTransports(array &$issues, array $transports): void
    {
        foreach ($transports as $name => $transport) {
            if (!is_array($transport) || ($transport['driver'] ?? null) !== 'pdo') {
                continue;
            }

            $connection = $transport['connection'] ?? null;
            $driver = is_string($connection)
                ? $this->config->get('database.connections.' . $connection . '.driver')
                : null;
            if (!in_array($driver, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true)) {
                $issues[] = new ConfigIssue(
                    sprintf('cache.transports.%s PDO transport must use a shared MySQL or PostgreSQL connection.', $name),
                    'cache.transports.' . $name,
                );
            }
        }
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
     * @param array<string, mixed> $stores
     */
    private function validateNodeCacheStores(array &$issues, array $stores): void
    {
        foreach ($stores as $name => $store) {
            if (!is_array($store) || ($store['driver'] ?? null) !== 'node') {
                continue;
            }

            $file = $store['sqlite_file'] ?? $store['file'] ?? $store['path'] ?? null;
            if (!is_string($file) || $file === '' || !$this->isNodeCachePath($file)) {
                $issues[] = new ConfigIssue(
                    sprintf('cache.stores.%s node SQLite files must be inside the configured cache directory.', $name),
                    'cache.stores.' . $name,
                );
            }
        }
    }

    /**
     * @param list<ConfigIssue> $issues
     */
    private function validateNotificationTransport(array &$issues): void
    {
        $transport = $this->config->get('notifications.auth.transport');
        if (!is_string($transport) || $transport === '') {
            $issues[] = new ConfigIssue(
                'notifications.auth.transport must be configured when auth.drivers.notifications uses talkingbytes.',
                'notifications.auth.transport',
            );

            return;
        }

        if (!in_array($transport, ['fake', 'log', 'mail', 'null', 'replace-me', 'sendmail', 'smtp', 'spool'], true)) {
            $issues[] = new ConfigIssue(
                sprintf('notifications.auth.transport "%s" is not supported.', $transport),
                'notifications.auth.transport',
            );

            return;
        }

        if (in_array($transport, ['null', 'replace-me'], true)) {
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
