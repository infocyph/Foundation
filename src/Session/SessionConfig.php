<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Infocyph\Foundation\Config\ConfigRepository;

final readonly class SessionConfig
{
    public function __construct(
        public string $driver,
        public int $lifetimeSeconds,
        public int $maxPayloadBytes,
        public string $cookieName,
        public string $cookiePath,
        public ?string $cookieDomain,
        public bool $cookieSecure,
        public bool $cookieHttpOnly,
        public string $cookieSameSite,
        public string $filePath,
        public ?string $cacheStore,
        public ?string $databaseConnection,
        public string $databaseTable,
        public bool $lockEnabled,
        public ?string $lockStore,
        public float $lockWaitSeconds,
        public float $lockLeaseSeconds,
        public string $csrfHeader,
        public string $csrfField,
        public bool $csrfCheckOrigin,
        public ?string $csrfOrigin,
    ) {}

    public static function fromRepository(ConfigRepository $config, string $defaultFilePath): self
    {
        $driver = self::oneOf($config->get('session.driver', 'file'), ['array', 'file', 'cache', 'database'], 'session.driver');
        $sameSite = self::oneOf($config->get('session.cookie.same_site', 'Lax'), ['Lax', 'Strict', 'None'], 'session.cookie.same_site');
        $secure = self::bool($config->get('session.cookie.secure', true), 'session.cookie.secure');
        if ($sameSite === 'None' && !$secure) {
            throw new \InvalidArgumentException('session.cookie.secure must be true when SameSite is None.');
        }

        return new self(
            driver: $driver,
            lifetimeSeconds: self::positiveInt($config->get('session.lifetime', 7_200), 'session.lifetime'),
            maxPayloadBytes: self::positiveInt($config->get('session.max_payload_bytes', 65_536), 'session.max_payload_bytes'),
            cookieName: self::nonEmptyString($config->get('session.cookie.name', 'infbyte_session'), 'session.cookie.name'),
            cookiePath: self::nonEmptyString($config->get('session.cookie.path', '/'), 'session.cookie.path'),
            cookieDomain: self::nullableString($config->get('session.cookie.domain')),
            cookieSecure: $secure,
            cookieHttpOnly: self::bool($config->get('session.cookie.http_only', true), 'session.cookie.http_only'),
            cookieSameSite: $sameSite,
            filePath: self::nonEmptyString($config->get('session.stores.file.path', $defaultFilePath), 'session.stores.file.path'),
            cacheStore: self::nullableString($config->get('session.stores.cache.store')),
            databaseConnection: self::nullableString($config->get('session.stores.database.connection')),
            databaseTable: self::identifier($config->get('session.stores.database.table', 'sessions'), 'session.stores.database.table'),
            lockEnabled: self::bool($config->get('session.lock.enabled', false), 'session.lock.enabled'),
            lockStore: self::nullableString($config->get('session.lock.store')),
            lockWaitSeconds: self::nonNegativeFloat($config->get('session.lock.wait', 2.0), 'session.lock.wait'),
            lockLeaseSeconds: self::positiveFloat($config->get('session.lock.lease', 30.0), 'session.lock.lease'),
            csrfHeader: self::nonEmptyString($config->get('session.csrf.header', 'X-CSRF-Token'), 'session.csrf.header'),
            csrfField: self::nonEmptyString($config->get('session.csrf.field', '_token'), 'session.csrf.field'),
            csrfCheckOrigin: self::bool($config->get('session.csrf.check_origin', true), 'session.csrf.check_origin'),
            csrfOrigin: self::nullableString($config->get('session.csrf.origin')),
        );
    }

    private static function bool(mixed $value, string $key): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a boolean.', $key));
        }

        return $value;
    }

    private static function identifier(mixed $value, string $key): string
    {
        $value = self::nonEmptyString($value, $key);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s must be a portable SQL identifier.', $key));
        }

        return $value;
    }

    private static function nonEmptyString(mixed $value, string $key): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf('%s must be a non-empty string.', $key));
        }

        return $value;
    }

    private static function nonNegativeFloat(mixed $value, string $key): float
    {
        if ((!is_int($value) && !is_float($value)) || $value < 0) {
            throw new \InvalidArgumentException(sprintf('%s must be zero or greater.', $key));
        }

        return (float) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $key): string
    {
        $value = self::nonEmptyString($value, $key);
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be one of: %s.',
                $key,
                implode(', ', $allowed),
            ));
        }

        return $value;
    }

    private static function positiveFloat(mixed $value, string $key): float
    {
        if ((!is_int($value) && !is_float($value)) || $value <= 0) {
            throw new \InvalidArgumentException(sprintf('%s must be greater than zero.', $key));
        }

        return (float) $value;
    }

    private static function positiveInt(mixed $value, string $key): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer.', $key));
        }

        return $value;
    }
}
