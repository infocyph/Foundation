<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

use Infocyph\ArrayKit\Config\Support\Environment;
use Infocyph\Epicrypt\Exception\ConfigurationException as EpicryptConfigurationException;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyDeriver;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Epicrypt\Token\Jwt\Enum\JweKeyManagementAlgorithm;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class OAuthAuthorizationCodeKeyResolver
{
    public const string DOMAIN = 'foundation.auth.oauth.authorization-code.v1';

    private const string DEVELOPMENT_MASTER = 'foundation-oauth-authorization-code-development-master';

    public function __construct(private ConfigRepository $config) {}

    public function issuer(): string
    {
        $issuer = $this->config->get('auth.oauth.issuer');
        if (!is_string($issuer) || trim($issuer) === '') {
            throw new ConfigurationException('OAuth authorization-code protection requires auth.oauth.issuer.');
        }

        return trim($issuer);
    }

    public function resolve(): KeyRing
    {
        $configured = $this->config->get('auth.oauth.authorization_code_protection.keys', []);
        if (!is_array($configured) || !array_is_list($configured)) {
            throw new ConfigurationException('auth.oauth.authorization_code_protection.keys must be a list.');
        }

        $issuer = $this->issuer();
        if ($configured === []) {
            if ($this->config->isProduction()) {
                throw new ConfigurationException(
                    'Production OAuth requires explicit authorization-code protection keys.',
                );
            }

            $ring = new KeyRing([
                new KeyRingEntry(
                    id: 'development',
                    key: $this->derive(self::DEVELOPMENT_MASTER, 'development'),
                    status: KeyStatus::ACTIVE,
                    purpose: KeyPurpose::OAUTH_AUTHORIZATION_CODE_PROTECTION,
                    algorithm: JweKeyManagementAlgorithm::DIRECT->value,
                    issuer: $issuer,
                ),
            ]);
            $this->assertWritable($ring, $issuer);

            return $ring;
        }

        $entries = [];
        foreach ($configured as $definition) {
            $entries[] = $this->entry($definition, $issuer);
        }

        try {
            $ring = new KeyRing($entries);
            $this->assertWritable($ring, $issuer);
        } catch (EpicryptConfigurationException $exception) {
            throw new ConfigurationException(
                'OAuth authorization-code protection key ring is invalid.',
                previous: $exception,
            );
        }

        return $ring;
    }

    private function assertWritable(KeyRing $ring, string $issuer): void
    {
        $ring->activeForWrite(
            KeyPurpose::OAUTH_AUTHORIZATION_CODE_PROTECTION,
            JweKeyManagementAlgorithm::DIRECT->value,
            $issuer,
        );
    }

    private function derive(#[\SensitiveParameter] string $master, string $id): string
    {
        if (strlen($master) < 32) {
            throw new ConfigurationException(
                'OAuth authorization-code protection master keys must contain at least 32 bytes.',
            );
        }

        try {
            return new KeyDeriver()->derivePurposeKeyBinary(
                $master,
                self::DOMAIN . ':' . $id,
                length: 32,
            );
        } catch (EpicryptConfigurationException $exception) {
            throw new ConfigurationException(
                'OAuth authorization-code protection purpose-key derivation failed.',
                previous: $exception,
            );
        }
    }

    private function entry(mixed $definition, string $issuer): KeyRingEntry
    {
        if (!is_array($definition)) {
            throw new ConfigurationException(
                'OAuth authorization-code protection key definitions must be arrays.',
            );
        }

        $id = $definition['id'] ?? null;
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1) {
            throw new ConfigurationException(
                'OAuth authorization-code protection key ids must be Base64URL-safe identifiers.',
            );
        }

        $environment = $this->environmentName($definition['environment'] ?? null);
        $master = Environment::get($environment);
        if (!is_string($master) || $master === '') {
            throw new ConfigurationException(sprintf(
                'OAuth authorization-code protection key environment %s is unavailable.',
                $environment,
            ));
        }

        return new KeyRingEntry(
            id: $id,
            key: $this->derive($master, $id),
            status: $this->status($definition['status'] ?? null),
            purpose: KeyPurpose::OAUTH_AUTHORIZATION_CODE_PROTECTION,
            algorithm: JweKeyManagementAlgorithm::DIRECT->value,
            notBefore: $this->timestamp($definition['not_before'] ?? null),
            notAfter: $this->timestamp($definition['not_after'] ?? null),
            issuer: $issuer,
        );
    }

    private function environmentName(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Z][A-Z0-9_]{1,127}\z/D', $value) !== 1) {
            throw new ConfigurationException(
                'OAuth authorization-code protection environments must use uppercase shell-variable syntax.',
            );
        }

        return $value;
    }

    private function status(mixed $value): KeyStatus
    {
        return match ($value) {
            'active' => KeyStatus::ACTIVE,
            'fallback' => KeyStatus::FALLBACK,
            default => throw new ConfigurationException(
                'OAuth authorization-code protection key status must be active or fallback.',
            ),
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

        throw new ConfigurationException(
            'OAuth authorization-code protection key validity timestamps must be positive integers.',
        );
    }
}
