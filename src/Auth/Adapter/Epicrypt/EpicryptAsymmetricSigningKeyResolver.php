<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Epicrypt\Security\AsymmetricSigningKeySet;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class EpicryptAsymmetricSigningKeyResolver
{
    public function __construct(private ConfigRepository $config) {}

    public function resolve(string $prefix, string $issuer, KeyPurpose $purpose): AsymmetricSigningKeySet
    {
        if ($issuer === '') {
            throw new ConfigurationException('Epicrypt signing-key issuer is required.');
        }

        $algorithm = $this->algorithm($prefix);
        $activeKeyId = $this->requiredKeyId($prefix . '.active_key_id');
        $publicKeys = $this->publicKeys($prefix, $issuer, $activeKeyId, $algorithm, $purpose);

        return new AsymmetricSigningKeySet(
            issuer: $issuer,
            activeKeyId: $activeKeyId,
            privateKey: $this->readKey($this->config->get($prefix . '.private_key')),
            publicKeys: new KeyRing($publicKeys),
            algorithm: $algorithm,
            purpose: $purpose,
        );
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function algorithm(string $prefix): AsymmetricJwtAlgorithm
    {
        $configured = $this->config->get($prefix . '.algorithm', 'ES256');
        $algorithm = is_string($configured)
            ? AsymmetricJwtAlgorithm::tryFrom(trim($configured))
            : null;

        return $algorithm
            ?? throw new ConfigurationException('Epicrypt signing algorithm is invalid.');
    }

    private function basePath(): string
    {
        $base = $this->config->get('app.base_path');

        return is_string($base) && trim($base) !== ''
            ? rtrim($base, DIRECTORY_SEPARATOR)
            : (getcwd() ?: '.');
    }

    private function nullableTimestamp(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @return list<KeyRingEntry>
     */
    private function publicKeys(
        string $prefix,
        string $issuer,
        string $activeKeyId,
        AsymmetricJwtAlgorithm $algorithm,
        KeyPurpose $purpose,
    ): array {
        $configured = $this->config->get($prefix . '.public_keys', []);
        if (!is_array($configured) || $configured === [] || !array_is_list($configured)) {
            throw new ConfigurationException('Epicrypt public signing keys are not configured.');
        }

        $entries = [];
        foreach ($configured as $item) {
            if (!is_array($item)) {
                throw new ConfigurationException('Epicrypt public signing key configuration is invalid.');
            }
            $id = $item['id'] ?? null;
            $path = $item['path'] ?? null;
            $status = $item['status'] ?? null;
            if (!is_string($id)
                || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1
                || !is_string($path)
            ) {
                throw new ConfigurationException('Epicrypt public signing key configuration is invalid.');
            }

            $resolvedStatus = match ($status) {
                'active' => KeyStatus::ACTIVE,
                'fallback' => KeyStatus::FALLBACK,
                default => throw new ConfigurationException('Epicrypt public signing key status is invalid.'),
            };
            if (($resolvedStatus === KeyStatus::ACTIVE) !== hash_equals($activeKeyId, $id)) {
                throw new ConfigurationException('Epicrypt active signing key configuration is inconsistent.');
            }

            $entries[] = new KeyRingEntry(
                id: $id,
                key: $this->readKey($path),
                status: $resolvedStatus,
                purpose: $purpose,
                algorithm: $algorithm->value,
                notBefore: $this->nullableTimestamp($item['not_before'] ?? null),
                notAfter: $this->nullableTimestamp($item['not_after'] ?? null),
                issuer: $issuer,
            );
        }

        return $entries;
    }

    private function readKey(mixed $locator): string
    {
        if (!is_string($locator) || trim($locator) === '') {
            throw new ConfigurationException('Epicrypt signing key locator is not configured.');
        }

        $path = trim($locator);
        if (!$this->absolute($path)) {
            $path = $this->basePath() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigurationException('Epicrypt signing key material is unavailable.');
        }

        $key = file_get_contents($path);

        return is_string($key) && trim($key) !== ''
            ? $key
            : throw new ConfigurationException('Epicrypt signing key material is unavailable.');
    }

    private function requiredKeyId(string $key): string
    {
        $value = $this->config->get($key);
        if (!is_string($value)
            || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', trim($value)) !== 1
        ) {
            throw new ConfigurationException('Epicrypt signing key id is invalid.');
        }

        return trim($value);
    }
}
