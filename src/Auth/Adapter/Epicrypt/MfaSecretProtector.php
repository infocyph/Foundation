<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Epicrypt\DataProtection\StringProtector;
use Infocyph\Epicrypt\Exception\Crypto\DecryptionException;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;

final readonly class MfaSecretProtector
{
    public const string PURPOSE = 'foundation.auth.mfa-secret.v1';

    private const string MARKER = '_secret_protection';

    /** @var list<string> */
    private const array SECRET_FIELDS = ['secret', 'pin'];

    public function __construct(
        #[\SensitiveParameter]
        private KeyRing $keys,
        private bool $allowLegacyPlaintext = false,
        private StringProtector $protector = new StringProtector(),
    ) {}

    public function protect(MfaFactor $factor): MfaFactor
    {
        $metadata = $factor->metadata;
        $otp = $this->otpMetadata($metadata);
        if ($otp === null) {
            return $factor;
        }

        $changed = false;
        foreach (self::SECRET_FIELDS as $field) {
            $value = $otp[$field] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (str_starts_with($value, 'ep2.')) {
                throw new \LogicException('MFA persistence expects plaintext secrets before protection.');
            }

            $otp[$field] = $this->protector->protectWithKeyRing(
                $value,
                $this->keys,
                $this->options($factor, $field),
            );
            $changed = true;
        }

        if (!$changed) {
            return $factor;
        }

        $otp[self::MARKER] = [
            'purpose' => self::PURPOSE,
            'version' => 1,
        ];
        $metadata['otp'] = $otp;

        return $this->factorWithMetadata($factor, $metadata);
    }

    public function unprotect(MfaFactor $factor): MfaFactor
    {
        return $this->unprotectResult($factor)->factor;
    }

    public function unprotectResult(MfaFactor $factor): MfaSecretProtectionResult
    {
        $metadata = $factor->metadata;
        $otp = $this->otpMetadata($metadata);
        if ($otp === null) {
            return new MfaSecretProtectionResult($factor);
        }

        $marker = $otp[self::MARKER] ?? null;
        if ($marker !== null && !$this->validMarker($marker)) {
            throw new DecryptionException('MFA secret protection metadata is invalid.');
        }

        $changed = false;
        $legacyPlaintext = false;
        $usedFallbackKey = false;
        foreach (self::SECRET_FIELDS as $field) {
            $value = $otp[$field] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }

            if (!str_starts_with($value, 'ep2.')) {
                if ($marker !== null || !$this->allowLegacyPlaintext) {
                    throw new DecryptionException('MFA factor contains an unprotected symmetric secret.');
                }

                $legacyPlaintext = true;

                continue;
            }

            $result = $this->protector->unprotectWithKeyRing(
                $value,
                $this->keys,
                $this->options($factor, $field),
            );
            $otp[$field] = $result->value;
            $usedFallbackKey = $usedFallbackKey || $result->usedFallbackKey;
            $changed = true;
        }

        if ($marker !== null) {
            unset($otp[self::MARKER]);
            $changed = true;
        }

        if ($changed) {
            $metadata['otp'] = $otp;
            $factor = $this->factorWithMetadata($factor, $metadata);
        }

        return new MfaSecretProtectionResult(
            factor: $factor,
            usedFallbackKey: $usedFallbackKey,
            legacyPlaintext: $legacyPlaintext,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function factorWithMetadata(MfaFactor $factor, array $metadata): MfaFactor
    {
        return new MfaFactor(
            id: $factor->id,
            accountId: $factor->accountId,
            type: $factor->type,
            label: $factor->label,
            enabled: $factor->enabled,
            createdAt: $factor->createdAt,
            metadata: $metadata,
            revision: $factor->revision,
        );
    }

    private function options(MfaFactor $factor, string $field): ProtectionOptions
    {
        return new ProtectionOptions(
            self::PURPOSE,
            implode("\0", [
                'foundation:mfa-factor:v1',
                $factor->accountId,
                $factor->id,
                $factor->type,
                $field,
            ]),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>|null
     */
    private function otpMetadata(array $metadata): ?array
    {
        $otp = $metadata['otp'] ?? null;
        if (!is_array($otp)) {
            return null;
        }

        $normalized = [];
        foreach ($otp as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function validMarker(mixed $marker): bool
    {
        return is_array($marker)
            && ($marker['version'] ?? null) === 1
            && ($marker['purpose'] ?? null) === self::PURPOSE;
    }
}
