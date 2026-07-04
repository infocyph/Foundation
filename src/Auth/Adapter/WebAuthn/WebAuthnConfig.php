<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class WebAuthnConfig
{
    /**
     * @param list<string> $algorithms
     * @param list<string> $transports
     */
    public function __construct(
        public ?string $rpId,
        public string $rpName,
        public ?string $origin,
        public int $timeout,
        public int $challengeTtl,
        public string $userVerification,
        public string $residentKey,
        public string $attestation,
        public array $algorithms,
        public array $transports,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $attestation = self::string($config['attestation'] ?? null, 'none');
        if ($attestation !== 'none') {
            throw new ConfigurationException('Only WebAuthn attestation=none is currently supported.');
        }

        $userVerification = self::enumString(
            $config['user_verification'] ?? null,
            ['required', 'preferred', 'discouraged'],
            'preferred',
            'auth.webauthn.user_verification',
        );
        $residentKey = self::enumString(
            $config['resident_key'] ?? null,
            ['required', 'preferred', 'discouraged'],
            'preferred',
            'auth.webauthn.resident_key',
        );
        $algorithms = ValueNormalizer::stringList($config['algorithms'] ?? ['ES256', 'RS256']);
        self::assertAllowedStrings(
            $algorithms,
            ['ES256', 'RS256'],
            'auth.webauthn.algorithms',
        );
        $transports = ValueNormalizer::stringList($config['transports'] ?? ['internal', 'hybrid', 'usb', 'nfc', 'ble']);
        self::assertAllowedStrings(
            $transports,
            ['internal', 'hybrid', 'usb', 'nfc', 'ble'],
            'auth.webauthn.transports',
        );

        return new self(
            rpId: ValueNormalizer::nullableString($config['rp_id'] ?? null),
            rpName: self::string($config['rp_name'] ?? null, 'Foundation'),
            origin: ValueNormalizer::nullableString($config['origin'] ?? null),
            timeout: max(1, self::int($config['timeout'] ?? null, 60000)),
            challengeTtl: max(1, self::int($config['challenge_ttl'] ?? null, 300)),
            userVerification: $userVerification,
            residentKey: $residentKey,
            attestation: $attestation,
            algorithms: $algorithms,
            transports: $transports,
        );
    }

    /**
     * @param list<string> $values
     * @param list<string> $allowed
     */
    private static function assertAllowedStrings(array $values, array $allowed, string $key): void
    {
        if ($values === []) {
            throw new ConfigurationException(sprintf('%s must not be empty.', $key));
        }

        foreach ($values as $value) {
            if (in_array($value, $allowed, true)) {
                continue;
            }

            throw new ConfigurationException(sprintf(
                '%s contains unsupported value "%s". Allowed values: %s.',
                $key,
                $value,
                implode(', ', $allowed),
            ));
        }
    }

    /**
     * @param list<string> $allowed
     */
    private static function enumString(mixed $value, array $allowed, string $default, string $key): string
    {
        $resolved = self::string($value, $default);

        if (!in_array($resolved, $allowed, true)) {
            throw new ConfigurationException(sprintf(
                '%s must be one of: %s.',
                $key,
                implode(', ', $allowed),
            ));
        }

        return $resolved;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function string(mixed $value, string $default): string
    {
        return is_string($value) && $value !== ''
            ? $value
            : $default;
    }
}
