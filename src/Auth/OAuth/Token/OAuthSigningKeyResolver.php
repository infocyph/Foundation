<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Jwks;
use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Epicrypt\Token\Jwt\JwtPolicy;
use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class OAuthSigningKeyResolver
{
    public function __construct(
        private ConfigRepository $config,
        private ?OAuthAuditRecorder $audit = null,
    ) {}

    public function resolve(): OAuthSigningKeySet
    {
        try {
            $resolved = $this->resolveConfigured();
        } catch (\Throwable $exception) {
            $this->recordReadiness(['result' => 'failure'], AuthEventSeverity::CRITICAL);

            throw $exception;
        }

        $this->recordReadiness([
            'result' => 'ready',
            'algorithm' => $resolved->algorithm->value,
            'key_id' => $resolved->activeKeyId,
        ]);

        return $resolved;
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function algorithm(): AsymmetricJwtAlgorithm
    {
        $configured = $this->config->get('auth.oauth.signing.algorithm', 'RS256');
        $algorithm = is_string($configured) ? AsymmetricJwtAlgorithm::tryFrom(trim($configured)) : null;

        if (!$algorithm instanceof AsymmetricJwtAlgorithm) {
            throw new ConfigurationException('OAuth signing algorithm is invalid.');
        }

        return $algorithm;
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

    /** @return list<KeyRingEntry> */
    private function publicKeyEntries(
        string $issuer,
        string $activeKeyId,
        AsymmetricJwtAlgorithm $algorithm,
    ): array {
        $configured = $this->config->get('auth.oauth.signing.public_keys', []);
        if (!is_array($configured) || $configured === [] || !array_is_list($configured)) {
            throw new ConfigurationException('OAuth public signing keys are not configured.');
        }

        $entries = [];
        foreach ($configured as $item) {
            if (!is_array($item)) {
                throw new ConfigurationException('OAuth public signing key configuration is invalid.');
            }

            $id = $item['id'] ?? null;
            $path = $item['path'] ?? null;
            $status = $item['status'] ?? null;
            if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1 || !is_string($path)) {
                throw new ConfigurationException('OAuth public signing key configuration is invalid.');
            }

            $resolvedStatus = match ($status) {
                'active' => KeyStatus::ACTIVE,
                'fallback' => KeyStatus::FALLBACK,
                default => throw new ConfigurationException('OAuth public signing key status is invalid.'),
            };
            if (($resolvedStatus === KeyStatus::ACTIVE) !== hash_equals($activeKeyId, $id)) {
                throw new ConfigurationException('OAuth active signing key configuration is inconsistent.');
            }

            $entries[] = new KeyRingEntry(
                id: $id,
                key: $this->readKey($path),
                status: $resolvedStatus,
                purpose: KeyPurpose::JWT_SIGNING,
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
            throw new ConfigurationException('OAuth signing key locator is not configured.');
        }

        $path = trim($locator);
        if (!$this->absolute($path)) {
            $path = $this->basePath() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigurationException('OAuth signing key material is unavailable.');
        }

        $key = file_get_contents($path);
        if (!is_string($key) || trim($key) === '') {
            throw new ConfigurationException('OAuth signing key material is unavailable.');
        }

        return $key;
    }

    /** @param array<string, mixed> $metadata */
    private function recordReadiness(array $metadata, AuthEventSeverity $severity = AuthEventSeverity::INFO): void
    {
        if (!$this->audit instanceof OAuthAuditRecorder) {
            return;
        }

        try {
            $this->audit->record(
                AuthEventType::OAUTH_KEY_READINESS,
                metadata: $metadata,
                severity: $severity,
            );
        } catch (\Throwable) {
            // Signing readiness is authoritative; an audit backend outage must not replace its result.
        }
    }

    private function requiredKeyId(string $key): string
    {
        $value = $this->requiredString($key);
        if (preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $value) !== 1) {
            throw new ConfigurationException('OAuth signing key id is invalid.');
        }

        return $value;
    }

    private function requiredString(string $key): string
    {
        $value = $this->config->get($key);
        if (!is_string($value) || trim($value) === '') {
            throw new ConfigurationException('OAuth signing configuration is incomplete.');
        }

        return trim($value);
    }

    private function resolveConfigured(): OAuthSigningKeySet
    {
        $issuer = $this->requiredString('auth.oauth.issuer');
        $activeKeyId = $this->requiredKeyId('auth.oauth.signing.active_key_id');
        $algorithm = $this->algorithm();
        $privateKey = $this->readKey($this->config->get('auth.oauth.signing.private_key'));
        $entries = $this->publicKeyEntries($issuer, $activeKeyId, $algorithm);
        $ring = new KeyRing($entries);
        $active = $ring->activeForWrite(KeyPurpose::JWT_SIGNING, $algorithm->value, $issuer);

        if (!hash_equals($activeKeyId, $active->id)) {
            throw new ConfigurationException('OAuth active signing key configuration is inconsistent.');
        }

        $this->verifyKeyPair($issuer, $activeKeyId, $privateKey, $ring, $algorithm);
        new Jwks()->exportFromKeyRing($ring, $algorithm, $issuer);

        return new OAuthSigningKeySet(
            issuer: $issuer,
            activeKeyId: $activeKeyId,
            privateKey: $privateKey,
            publicKeys: $ring,
            algorithm: $algorithm,
        );
    }

    private function verifyKeyPair(
        string $issuer,
        string $activeKeyId,
        #[\SensitiveParameter]
        string $privateKey,
        KeyRing $publicKeys,
        AsymmetricJwtAlgorithm $algorithm,
    ): void {
        $audience = 'foundation-oauth-key-readiness';
        $claims = JwtClaims::issue(
            issuer: $issuer,
            subject: 'foundation-oauth-key-readiness',
            audiences: [$audience],
            ttlSeconds: 30,
            custom: ['client_id' => 'foundation-oauth-key-readiness'],
        );
        $token = AsymmetricJwt::issuer(
            $privateKey,
            'at+jwt',
            $activeKeyId,
            $algorithm,
        )->issue($claims);
        $valid = AsymmetricJwt::verifier(
            $publicKeys,
            JwtPolicy::oauthAccessToken($issuer, $audience),
            $algorithm,
        )->verify($token);

        if (!$valid) {
            throw new ConfigurationException('OAuth active private and public signing keys do not match.');
        }
    }
}
