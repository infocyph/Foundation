<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\ArrayKit\Config\Support\Environment;
use Infocyph\Epicrypt\DataProtection\ProtectionAlgorithm;
use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Epicrypt\DataProtection\StringProtector;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyDeriver;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\MfaSecretProtector;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class AuthMfaKeyResolver
{
    public const string RECOVERY_KEY_DOMAIN = 'foundation.auth.recovery-hmac.v1';

    private const string DEFAULT_RECOVERY_ENVIRONMENT = 'AUTH_OTP_RECOVERY_HMAC_KEY';

    private const string DEVELOPMENT_MFA_KEY = 'Zm91bmRhdGlvbi1tZmEtZGV2ZWxvcG1lbnQtcm9vdCE';

    private const string DEVELOPMENT_RECOVERY_MASTER = 'foundation-recovery-development!';

    public function __construct(private ConfigRepository $config) {}

    public function allowLegacyPlaintext(): bool
    {
        return $this->config->get('auth.otp.secret_protection.allow_legacy_plaintext', false) === true;
    }

    public function assertProductionReady(): void
    {
        if (!$this->config->isProduction()) {
            return;
        }

        $this->recoveryHmacKey();
        $this->mfaSecretKeyRing();
    }

    public function mfaSecretKeyRing(): KeyRing
    {
        $configured = $this->config->get('auth.otp.secret_protection.keys', []);
        if (!is_array($configured) || !array_is_list($configured)) {
            throw new ConfigurationException('auth.otp.secret_protection.keys must be a list.');
        }
        if ($configured === []) {
            if ($this->config->isProduction()) {
                throw new ConfigurationException('Production OTP requires explicit MFA secret-protection keys.');
            }

            return new KeyRing([
                new KeyRingEntry(
                    id: 'development',
                    key: self::DEVELOPMENT_MFA_KEY,
                    status: KeyStatus::ACTIVE,
                    purpose: KeyPurpose::DATA_PROTECTION,
                    algorithm: ProtectionAlgorithm::XCHACHA20_POLY1305->value,
                ),
            ]);
        }

        $entries = [];
        foreach ($configured as $definition) {
            $entries[] = $this->mfaKeyEntry($definition);
        }

        $ring = new KeyRing($entries);
        $ring->activeForWrite(
            KeyPurpose::DATA_PROTECTION,
            ProtectionAlgorithm::XCHACHA20_POLY1305->value,
        );

        return $ring;
    }

    public function recoveryHmacKey(): string
    {
        $environment = $this->environmentName(
            $this->config->get(
                'auth.otp.recovery_codes.hmac_key_environment',
                self::DEFAULT_RECOVERY_ENVIRONMENT,
            ),
        );
        $resolved = Environment::get($environment);
        if (!is_string($resolved) || $resolved === '') {
            if ($this->config->isProduction()) {
                throw new ConfigurationException(sprintf(
                    'Production OTP recovery codes require the %s secret.',
                    $environment,
                ));
            }

            $resolved = self::DEVELOPMENT_RECOVERY_MASTER;
        }
        if (strlen($resolved) < 32) {
            throw new ConfigurationException('OTP recovery-code master key must contain at least 32 bytes.');
        }

        return (new KeyDeriver())->derivePurposeKeyBinary(
            $resolved,
            self::RECOVERY_KEY_DOMAIN,
            length: 32,
        );
    }

    private function environmentName(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Z][A-Z0-9_]{1,127}\z/D', $value) !== 1) {
            throw new ConfigurationException('Authentication secret environment names must use uppercase shell-variable syntax.');
        }

        return $value;
    }

    private function mfaKeyEntry(mixed $definition): KeyRingEntry
    {
        if (!is_array($definition)) {
            throw new ConfigurationException('MFA secret-protection key definitions must be arrays.');
        }

        $id = $definition['id'] ?? null;
        $environment = $this->environmentName($definition['environment'] ?? null);
        $status = $this->keyStatus($definition['status'] ?? null);
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1) {
            throw new ConfigurationException('MFA secret-protection key ids must be Base64URL-safe identifiers.');
        }

        $key = Environment::get($environment);
        if (!is_string($key) || $key === '') {
            throw new ConfigurationException(sprintf(
                'MFA secret-protection key environment %s is unavailable.',
                $environment,
            ));
        }
        $this->assertProtectionKey($key);

        return new KeyRingEntry(
            id: $id,
            key: $key,
            status: $status,
            purpose: KeyPurpose::DATA_PROTECTION,
            algorithm: ProtectionAlgorithm::XCHACHA20_POLY1305->value,
            notBefore: $this->timestamp($definition['not_before'] ?? null),
            notAfter: $this->timestamp($definition['not_after'] ?? null),
        );
    }

    private function assertProtectionKey(#[\SensitiveParameter] string $key): void
    {
        try {
            (new StringProtector())->protect(
                'foundation-mfa-key-readiness',
                $key,
                new ProtectionOptions(MfaSecretProtector::PURPOSE),
            );
        } catch (\Throwable $exception) {
            throw new ConfigurationException(
                'MFA secret-protection key material is invalid for XChaCha20-Poly1305.',
                previous: $exception,
            );
        }
    }

    private function keyStatus(mixed $status): KeyStatus
    {
        return match ($status) {
            'active' => KeyStatus::ACTIVE,
            'fallback' => KeyStatus::FALLBACK,
            'disabled' => KeyStatus::DISABLED,
            'retired' => KeyStatus::RETIRED,
            default => throw new ConfigurationException('MFA secret-protection key status is invalid.'),
        };
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1) {
            $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($validated)) {
                return $validated;
            }
        }

        throw new ConfigurationException('MFA secret-protection key validity timestamps must be positive integers.');
    }
}
