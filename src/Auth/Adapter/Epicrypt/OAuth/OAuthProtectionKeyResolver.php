<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth;

use Infocyph\ArrayKit\Config\Support\Environment;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyDeriver;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Epicrypt\Token\Jwt\Enum\JweKeyManagementAlgorithm;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class OAuthProtectionKeyResolver
{
    private const string AUTHORIZATION_CODE_LABEL = 'foundation.oauth.authorization-code.v1';

    private const string REFRESH_TOKEN_LABEL = 'foundation.oauth.refresh-token.v1';

    public function __construct(
        private ConfigRepository $config,
        private ClockInterface $clock,
    ) {}

    public function authorizationCodeKeys(): KeyRing
    {
        return $this->resolve(
            'auth.oauth.authorization_code_protection.keys',
            KeyPurpose::OAUTH_AUTHORIZATION_CODE_PROTECTION,
            self::AUTHORIZATION_CODE_LABEL,
        );
    }

    public function refreshTokenKeys(): KeyRing
    {
        return $this->resolve(
            'auth.oauth.refresh_token_protection.keys',
            KeyPurpose::OAUTH_REFRESH_TOKEN_PROTECTION,
            self::REFRESH_TOKEN_LABEL,
        );
    }

    private function developmentKeyRing(KeyPurpose $purpose, string $label, string $issuer): KeyRing
    {
        $root = hash(
            'sha256',
            'foundation-development-only-oauth-root:' . $purpose->value,
            true,
        );

        return new KeyRing([
            new KeyRingEntry(
                id: 'development',
                key: new KeyDeriver()->derivePurposeKeyBinary($root, $label, $issuer, 32),
                status: KeyStatus::ACTIVE,
                purpose: $purpose,
                algorithm: JweKeyManagementAlgorithm::DIRECT->value,
                issuer: $issuer,
            ),
        ], new EpicryptClockAdapter($this->clock));
    }

    private function issuer(): string
    {
        $issuer = $this->config->get('auth.oauth.issuer');
        if (!is_string($issuer) || trim($issuer) === '') {
            throw new ConfigurationException('OAuth issuer is required before resolving protection keys.');
        }

        return trim($issuer);
    }

    private function keyStatus(mixed $status): KeyStatus
    {
        return match ($status) {
            'active' => KeyStatus::ACTIVE,
            'fallback' => KeyStatus::FALLBACK,
            default => throw new ConfigurationException('OAuth protection key status must be active or fallback.'),
        };
    }

    private function nullableTimestamp(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function resolve(string $configKey, KeyPurpose $purpose, string $label): KeyRing
    {
        $configured = $this->config->get($configKey, []);
        if (!is_array($configured) || !array_is_list($configured) || count($configured) > 8) {
            throw new ConfigurationException(sprintf('%s must contain at most 8 key locators.', $configKey));
        }

        $issuer = $this->issuer();
        if ($configured === []) {
            if ($this->config->isProduction()) {
                throw new ConfigurationException(sprintf('%s must contain between 1 and 8 key locators.', $configKey));
            }

            return $this->developmentKeyRing($purpose, $label, $issuer);
        }
        $deriver = new KeyDeriver();
        $entries = [];
        foreach ($configured as $item) {
            if (!is_array($item)) {
                throw new ConfigurationException(sprintf('%s entries must be maps.', $configKey));
            }
            $id = $item['id'] ?? null;
            $environment = $item['environment'] ?? null;
            if (!is_string($id)
                || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1
                || !is_string($environment)
                || preg_match('/\A[A-Z][A-Z0-9_]{1,127}\z/D', $environment) !== 1
            ) {
                throw new ConfigurationException(sprintf('%s contains an invalid key id or environment locator.', $configKey));
            }

            $root = Environment::get($environment);
            if (!is_string($root) || strlen($root) < 32) {
                throw new ConfigurationException(sprintf('%s must provide at least 32 raw key bytes.', $environment));
            }

            $entries[] = new KeyRingEntry(
                id: $id,
                key: $deriver->derivePurposeKeyBinary($root, $label, $issuer, 32),
                status: $this->keyStatus($item['status'] ?? null),
                purpose: $purpose,
                algorithm: JweKeyManagementAlgorithm::DIRECT->value,
                notBefore: $this->nullableTimestamp($item['not_before'] ?? null),
                notAfter: $this->nullableTimestamp($item['not_after'] ?? null),
                issuer: $issuer,
            );
        }

        return new KeyRing($entries, new EpicryptClockAdapter($this->clock));
    }
}
