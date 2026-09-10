<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\ArrayKit\Config\Support\Environment;
use Infocyph\Epicrypt\Exception\ConfigurationException as EpicryptConfigurationException;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyDeriver;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

/**
 * Resolves Foundation deployment key locators into Webrick's native signed-URL
 * configuration. Webrick remains the sole owner of URL canonicalization,
 * signing, expiry and verification mechanics.
 */
final readonly class SignedUrlKeyResolver
{
    public const string DOMAIN = 'foundation.signed-url.v1';

    public function __construct(private ConfigRepository $config) {}

    /**
     * Return only non-secret Webrick behavior options safe for compiled artifacts.
     *
     * @return array<string, mixed>|null
     */
    public function artifactOptions(): ?array
    {
        $options = $this->behaviorOptions();

        return $options === [] ? null : $options;
    }

    /**
     * Resolve runtime signing material for Webrick.
     *
     * @param array<string, mixed>|null $behaviorOptions
     */
    public function resolve(?array $behaviorOptions = null): ?SignedUrlConfig
    {
        $options = $this->behaviorOptions($behaviorOptions);
        $definitions = $this->config->get('router.signed_urls.keys', []);
        if (!is_array($definitions) || !array_is_list($definitions)) {
            throw new ConfigurationException('router.signed_urls.keys must be a list.');
        }

        if ($definitions === []) {
            $legacy = ValueNormalizer::nullableString($this->config->get('router.signed_urls.key'));
            if ($legacy === null) {
                return $options === [] ? null : SignedUrlConfig::fromArray($options);
            }
            if ($this->config->isProduction()) {
                throw new ConfigurationException(
                    'Production signed URLs require router.signed_urls.keys environment locators; raw router.signed_urls.key is not allowed.',
                );
            }

            return SignedUrlConfig::fromArray([
                ...$options,
                'generationKey' => $legacy,
                'verificationKeys' => [$legacy],
            ]);
        }

        $algorithm = ValueNormalizer::string(
            $options['algorithm'] ?? null,
            SignedUrlConfig::DEFAULT_ALGORITHM,
        );
        $entries = [];
        foreach ($definitions as $definition) {
            $entries[] = $this->entry($definition, $algorithm);
        }

        try {
            $ring = new KeyRing($entries);
            $active = $ring->activeForWrite(KeyPurpose::SIGNED_URL, $algorithm);
            $verificationKeys = array_map(
                static fn(KeyRingEntry $entry): string => $entry->key,
                $ring->readCandidates(KeyPurpose::SIGNED_URL, $algorithm),
            );
        } catch (EpicryptConfigurationException $exception) {
            throw new ConfigurationException('Signed-URL key ring is invalid.', previous: $exception);
        }

        return SignedUrlConfig::fromArray([
            ...$options,
            'generationKey' => $active->key,
            'verificationKeys' => $verificationKeys,
        ]);
    }

    /**
     * @param array<string, mixed>|null $configured
     * @return array<string, mixed>
     */
    private function behaviorOptions(?array $configured = null): array
    {
        $configured ??= ValueNormalizer::associativeArray($this->config->get('router.signed_urls.options', []));
        $normalized = [];

        foreach ($configured as $key => $value) {
            $normalized[match ($key) {
                'default_ttl' => 'defaultTtl',
                'expiry_param' => 'expiryParam',
                'ignored_query_params' => 'ignoredQueryParams',
                'payload_mode' => 'payloadMode',
                'signature_param' => 'signatureParam',
                default => $key,
            }] = $value;
        }

        // Runtime keys are owned by the Epicrypt lifecycle bridge and must never
        // be accepted through Webrick behavior options or release artifacts.
        unset(
            $normalized['generationKey'],
            $normalized['generation_key'],
            $normalized['verificationKeys'],
            $normalized['verification_keys'],
        );

        return $normalized;
    }

    private function entry(mixed $definition, string $algorithm): KeyRingEntry
    {
        if (!is_array($definition)) {
            throw new ConfigurationException('Signed-URL key definitions must be arrays.');
        }

        $id = $definition['id'] ?? null;
        $environment = $definition['environment'] ?? null;
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1) {
            throw new ConfigurationException('Signed-URL key ids must be Base64URL-safe identifiers.');
        }
        if (!is_string($environment) || preg_match('/\A[A-Z][A-Z0-9_]{1,127}\z/D', $environment) !== 1) {
            throw new ConfigurationException('Signed-URL key environment names must use uppercase shell-variable syntax.');
        }

        $master = Environment::get($environment);
        if (!is_string($master) || $master === '') {
            throw new ConfigurationException(sprintf('Signed-URL key environment %s is unavailable.', $environment));
        }

        try {
            $key = new KeyDeriver()->derivePurposeKeyBinary($master, self::DOMAIN, length: 32);
        } catch (EpicryptConfigurationException $exception) {
            throw new ConfigurationException('Signed-URL key derivation failed.', previous: $exception);
        }

        return new KeyRingEntry(
            id: $id,
            key: $key,
            status: $this->status($definition['status'] ?? null),
            purpose: KeyPurpose::SIGNED_URL,
            algorithm: $algorithm,
            notBefore: $this->timestamp($definition['not_before'] ?? null),
            notAfter: $this->timestamp($definition['not_after'] ?? null),
        );
    }

    private function status(mixed $value): KeyStatus
    {
        return match ($value) {
            'active' => KeyStatus::ACTIVE,
            'fallback' => KeyStatus::FALLBACK,
            'disabled' => KeyStatus::DISABLED,
            'retired' => KeyStatus::RETIRED,
            default => throw new ConfigurationException('Signed-URL key status is invalid.'),
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

        throw new ConfigurationException('Signed-URL key validity timestamps must be positive integers.');
    }
}
