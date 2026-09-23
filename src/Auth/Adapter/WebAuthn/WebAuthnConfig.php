<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class WebAuthnConfig
{
    public function __construct(
        public ?string $rpId,
        public ?string $origin,
        public int $challengeTtl,
        public bool $allowSubdomains = false,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $challengeTtl = self::integer($config['challenge_ttl'] ?? null, 300);
        if ($challengeTtl < 1 || $challengeTtl > 600) {
            throw new ConfigurationException('auth.webauthn.challenge_ttl must be between 1 and 600 seconds.');
        }

        return new self(
            rpId: ValueNormalizer::nullableString($config['rp_id'] ?? null),
            origin: ValueNormalizer::nullableString($config['origin'] ?? null),
            challengeTtl: $challengeTtl,
            allowSubdomains: self::boolean($config['allow_subdomains'] ?? false),
        );
    }

    private static function boolean(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_string($value) => in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true),
            default => false,
        };
    }

    private static function integer(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9]\d*)$/D', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }
}
