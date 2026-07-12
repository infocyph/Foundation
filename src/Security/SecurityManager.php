<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\Epicrypt\Certificate\CertificateAuthority;
use Infocyph\Epicrypt\Certificate\CertificateBuilder;
use Infocyph\Epicrypt\Certificate\CertificateChainVerifier;
use Infocyph\Epicrypt\Certificate\CertificateExpiry;
use Infocyph\Epicrypt\Certificate\CertificateFingerprint;
use Infocyph\Epicrypt\Certificate\CertificateKeyMatcher;
use Infocyph\Epicrypt\Certificate\CertificateParser;
use Infocyph\Epicrypt\Certificate\CsrBuilder;
use Infocyph\Epicrypt\Certificate\KeyExchange;
use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Certificate\OpenSSL\RsaCipher;
use Infocyph\Epicrypt\Certificate\PemNormalizer;
use Infocyph\Epicrypt\Certificate\Pkcs12;
use Infocyph\Epicrypt\Crypto\AeadCipher;
use Infocyph\Epicrypt\Crypto\Mac;
use Infocyph\Epicrypt\Crypto\PublicKeyBoxCipher;
use Infocyph\Epicrypt\Crypto\SealedBoxCipher;
use Infocyph\Epicrypt\Crypto\SecretBoxCipher;
use Infocyph\Epicrypt\Crypto\SecretStream;
use Infocyph\Epicrypt\Crypto\Signature;
use Infocyph\Epicrypt\DataProtection\EnvelopeProtector;
use Infocyph\Epicrypt\DataProtection\FileProtector;
use Infocyph\Epicrypt\DataProtection\StringProtector;
use Infocyph\Epicrypt\Generate\KeyMaterial\Enum\KeyPurpose;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyDerivationContext;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyDeriver;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator;
use Infocyph\Epicrypt\Generate\KeyMaterial\TokenMaterialGenerator;
use Infocyph\Epicrypt\Generate\NonceGenerator;
use Infocyph\Epicrypt\Generate\RandomBytesGenerator;
use Infocyph\Epicrypt\Generate\SaltGenerator;
use Infocyph\Epicrypt\Integrity\FileHasher;
use Infocyph\Epicrypt\Integrity\StringHasher;
use Infocyph\Epicrypt\Password\Generator\PasswordGenerator;
use Infocyph\Epicrypt\Password\PasswordHasher as EpicryptPasswordHasher;
use Infocyph\Epicrypt\Password\PasswordPolicyValidator as EpicryptPasswordPolicyValidator;
use Infocyph\Epicrypt\Password\PasswordStrength;
use Infocyph\Epicrypt\Password\Secret\WrappedSecretManager;
use Infocyph\Epicrypt\Security\ActionToken;
use Infocyph\Epicrypt\Security\CsrfTokenManager;
use Infocyph\Epicrypt\Security\EmailVerificationToken;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRotationHelper;
use Infocyph\Epicrypt\Security\PasswordResetToken;
use Infocyph\Epicrypt\Security\Policy\SecurityProfile;
use Infocyph\Epicrypt\Security\RememberToken;
use Infocyph\Epicrypt\Security\SignedUrl;
use Infocyph\Epicrypt\Security\SignedUrlOptions;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\Jwks;
use Infocyph\Epicrypt\Token\Jwt\SymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\Validation\ExpectedJwtClaims;
use Infocyph\Epicrypt\Token\Jwt\Validation\JwtValidationOptions;
use Infocyph\Epicrypt\Token\Jwt\Validation\RegisteredClaims;
use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Epicrypt\Token\Payload\SignedPayload;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Support\AbstractContainerManager;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class SecurityManager extends AbstractContainerManager
{
    public function accessTokens(): AccessTokenServiceInterface
    {
        return $this->typedService(
            AccessTokenServiceInterface::class,
            'Security access token service must resolve to AccessTokenServiceInterface.',
        );
    }

    public function actionTokens(): ActionToken
    {
        return new ActionToken($this->epicryptTokenSecret(), $this->epicryptTokenTtl());
    }

    public function aeadCipher(): AeadCipher
    {
        return AeadCipher::forProfile($this->epicryptProfile());
    }

    public function asymmetricJwt(
        RegisteredClaims|ExpectedJwtClaims|null $expectedClaims = null,
        ?string $passphrase = null,
        ?JwtValidationOptions $validationOptions = null,
    ): AsymmetricJwt {
        return AsymmetricJwt::forProfile(
            $this->epicryptProfile(),
            $expectedClaims,
            $passphrase,
            $validationOptions,
        );
    }

    public function certificateAuthority(): CertificateAuthority
    {
        return CertificateAuthority::openSsl();
    }

    public function certificateBuilder(string $digestAlgorithm = 'sha512'): CertificateBuilder
    {
        return CertificateBuilder::openSsl($digestAlgorithm);
    }

    public function certificateChainVerifier(): CertificateChainVerifier
    {
        return new CertificateChainVerifier();
    }

    public function certificateExpiry(): CertificateExpiry
    {
        return new CertificateExpiry();
    }

    public function certificateFingerprint(): CertificateFingerprint
    {
        return new CertificateFingerprint();
    }

    public function certificateKeyMatcher(): CertificateKeyMatcher
    {
        return new CertificateKeyMatcher();
    }

    public function certificateParser(): CertificateParser
    {
        return CertificateParser::openSsl();
    }

    public function csrBuilder(): CsrBuilder
    {
        return CsrBuilder::openSsl();
    }

    public function csrfTokens(): CsrfTokenManager
    {
        return new CsrfTokenManager(
            $this->requiredEpicryptSecret('csrf.secret'),
            $this->epicryptPositiveInt('csrf.ttl_seconds', 3600),
        );
    }

    /**
     * @param array<string, mixed>|KeyDerivationContext $context
     */
    public function deriveFromPassword(
        string $password,
        string $salt,
        int $length = 32,
        array|KeyDerivationContext $context = [],
    ): string {
        return $this->keyDeriver()->deriveFromPassword($password, $salt, $length, $context);
    }

    public function emailVerificationTokens(): EmailVerificationToken
    {
        return new EmailVerificationToken($this->epicryptTokenSecret(), $this->epicryptTokenTtl());
    }

    public function envelopeProtector(): EnvelopeProtector
    {
        return EnvelopeProtector::forProfile($this->epicryptProfile());
    }

    public function epicryptPasswords(): EpicryptPasswordHasher
    {
        return new EpicryptPasswordHasher();
    }

    public function fileHasher(?string $algorithm = null): FileHasher
    {
        return new FileHasher($algorithm ?? $this->integrityAlgorithm());
    }

    public function fileProtector(): FileProtector
    {
        return FileProtector::forProfile($this->epicryptProfile());
    }

    public function generateKey(int $length = 32, bool $asBase64Url = true): string
    {
        return $this->keyGenerator()->generate($length, $asBase64Url);
    }

    public function generateKeyForPurpose(
        KeyPurpose|string $purpose,
        bool $asBase64Url = true,
    ): string {
        return $this->keyGenerator()->forPurpose(
            $this->resolveKeyPurpose($purpose),
            $this->epicryptProfile(),
            $asBase64Url,
        );
    }

    public function generateMasterSecret(bool $asBase64Url = true): string
    {
        return $this->keyGenerator()->forMasterSecret($asBase64Url);
    }

    public function generateSecretBoxKey(bool $asBase64Url = true): string
    {
        return $this->keyGenerator()->forSecretBox($asBase64Url);
    }

    public function generateSecretStreamKey(bool $asBase64Url = true): string
    {
        return $this->keyGenerator()->forSecretStream($asBase64Url);
    }

    public function hashFile(string $path, string $key = '', ?string $algorithm = null): string
    {
        return $this->fileHasher($algorithm)->hash($path, $key);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function hashString(string $data, array $options = [], ?string $algorithm = null): string
    {
        return $this->stringHasher($algorithm)->hash($data, $options);
    }

    /**
     * @param array<string, mixed>|KeyDerivationContext $context
     */
    public function hkdf(string $inputKeyMaterial, int $length = 32, array|KeyDerivationContext $context = []): string
    {
        return $this->keyDeriver()->hkdf($inputKeyMaterial, $length, $context);
    }

    public function jwks(): Jwks
    {
        return new Jwks();
    }

    public function keyDeriver(): KeyDeriver
    {
        return new KeyDeriver();
    }

    public function keyExchange(): KeyExchange
    {
        return KeyExchange::sodium();
    }

    public function keyGenerator(): KeyMaterialGenerator
    {
        return new KeyMaterialGenerator();
    }

    public function keyRing(string $name): KeyRing
    {
        $configured = $this->config('epicrypt.key_rings.' . $name);
        if (!is_array($configured)) {
            throw new \RuntimeException(sprintf('Security Epicrypt key ring "%s" is not configured.', $name));
        }

        $active = ValueNormalizer::nullableString($configured['active'] ?? null);
        $keys = $configured['keys'] ?? null;
        if (!is_array($keys)) {
            throw new \RuntimeException(sprintf('Security Epicrypt key ring "%s" must define a "keys" array.', $name));
        }

        $normalized = $this->normalizeKeyRingEntries($keys);

        if ($normalized === []) {
            throw new \RuntimeException(sprintf('Security Epicrypt key ring "%s" must contain at least one key.', $name));
        }

        return new KeyRing($normalized, $active);
    }

    /**
     * @param array<string, string|array{key: string, status?: string, not_before?: int, not_after?: int, purpose?: string}> $keys
     */
    public function keyRingFromEntries(array $keys, ?string $activeKeyId = null): KeyRing
    {
        return new KeyRing($keys, $activeKeyId);
    }

    public function keyRotation(): KeyRotationHelper
    {
        return new KeyRotationHelper();
    }

    public function mac(): Mac
    {
        return new Mac();
    }

    public function nonces(): NonceGenerator
    {
        return new NonceGenerator();
    }

    public function opaqueTokens(): OpaqueToken
    {
        return new OpaqueToken();
    }

    public function openSslKeyPairs(): KeyPairGenerator
    {
        return KeyPairGenerator::openSsl();
    }

    public function passwordGenerator(): PasswordGenerator
    {
        return new PasswordGenerator();
    }

    public function passwordHasher(): PasswordHasherInterface
    {
        return $this->typedService(
            PasswordHasherInterface::class,
            'Security password hasher must resolve to PasswordHasherInterface.',
        );
    }

    public function passwordPolicy(): PasswordPolicyInterface
    {
        return $this->typedService(
            PasswordPolicyInterface::class,
            'Security password policy must resolve to PasswordPolicyInterface.',
        );
    }

    public function passwordPolicyValidator(): EpicryptPasswordPolicyValidator
    {
        return new EpicryptPasswordPolicyValidator();
    }

    public function passwordResetTokens(): PasswordResetToken
    {
        return new PasswordResetToken($this->epicryptTokenSecret(), $this->epicryptTokenTtl());
    }

    public function passwordStrength(): PasswordStrength
    {
        return new PasswordStrength();
    }

    public function passwordVerifier(): PasswordVerifierInterface
    {
        return $this->typedService(
            PasswordVerifierInterface::class,
            'Security password verifier must resolve to PasswordVerifierInterface.',
        );
    }

    public function pemNormalizer(): PemNormalizer
    {
        return new PemNormalizer();
    }

    public function pkcs12(): Pkcs12
    {
        return new Pkcs12();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function protectEnvelope(string $plaintext, string $masterKey, array $context = []): string
    {
        return $this->envelopeProtector()->encodeEnvelope(
            $this->envelopeProtector()->encrypt($plaintext, $masterKey, $context),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function protectEnvelopeWithKeyRing(string $plaintext, KeyRing|string $keyRing, array $context = []): string
    {
        $resolved = $this->resolveKeyRing($keyRing);

        return $this->envelopeProtector()->encodeEnvelope(
            $this->envelopeProtector()->encryptWithKeyRing($plaintext, $resolved, $context),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function protectString(string $plaintext, string $key, array $context = []): string
    {
        return $this->stringProtector()->encrypt($plaintext, $key, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function protectStringWithKeyRing(string $plaintext, KeyRing|string $keyRing, array $context = []): string
    {
        return $this->stringProtector()->encryptWithKeyRing($plaintext, $this->resolveKeyRing($keyRing), $context);
    }

    public function publicKeyBoxCipher(): PublicKeyBoxCipher
    {
        return new PublicKeyBoxCipher();
    }

    public function randomBytes(): RandomBytesGenerator
    {
        return new RandomBytesGenerator();
    }

    public function refreshTokens(): RefreshTokenServiceInterface
    {
        return $this->typedService(
            RefreshTokenServiceInterface::class,
            'Security refresh token service must resolve to RefreshTokenServiceInterface.',
        );
    }

    public function rememberTokens(): RememberToken
    {
        return new RememberToken($this->epicryptTokenSecret(), $this->epicryptTokenTtl());
    }

    public function rsaCipher(): RsaCipher
    {
        return new RsaCipher();
    }

    public function salts(): SaltGenerator
    {
        return new SaltGenerator();
    }

    public function sealedBoxCipher(): SealedBoxCipher
    {
        return new SealedBoxCipher();
    }

    public function secretBoxCipher(): SecretBoxCipher
    {
        return new SecretBoxCipher();
    }

    public function secretStream(string $key): SecretStream
    {
        return new SecretStream($key);
    }

    public function signature(): Signature
    {
        return new Signature();
    }

    public function signedPayload(?string $context = null): SignedPayload
    {
        return new SignedPayload($context);
    }

    public function signedUrls(): SignedUrl
    {
        return new SignedUrl(
            secret: $this->requiredEpicryptSecret('signed_urls.secret'),
            signatureParam: ValueNormalizer::string($this->config('epicrypt.signed_urls.signature_param'), 'ep_sig'),
            expiresParam: ValueNormalizer::string($this->config('epicrypt.signed_urls.expires_param'), 'ep_exp'),
            defaultOptions: $this->signedUrlOptions(),
        );
    }

    public function sodiumBoxKeyPairs(): KeyPairGenerator
    {
        return KeyPairGenerator::sodium();
    }

    public function sodiumSigningKeyPairs(): KeyPairGenerator
    {
        return KeyPairGenerator::sodiumSign();
    }

    public function stringHasher(?string $algorithm = null): StringHasher
    {
        return new StringHasher($algorithm ?? $this->integrityAlgorithm());
    }

    public function stringProtector(): StringProtector
    {
        return StringProtector::forProfile();
    }

    /**
     * @param array<string, mixed>|KeyDerivationContext $context
     */
    public function subkey(
        string $rootKey,
        int $subkeyId,
        int $length = 32,
        array|KeyDerivationContext $context = [],
    ): string {
        return $this->keyDeriver()->subkey($rootKey, $subkeyId, $length, $context);
    }

    public function symmetricJwt(
        RegisteredClaims|ExpectedJwtClaims|null $expectedClaims = null,
        ?JwtValidationOptions $validationOptions = null,
    ): SymmetricJwt {
        return SymmetricJwt::forProfile($this->epicryptProfile(), $expectedClaims, $validationOptions);
    }

    public function tokenMaterial(): TokenMaterialGenerator
    {
        return new TokenMaterialGenerator();
    }

    public function unprotectEnvelope(string $encodedEnvelope, string $masterKey): string
    {
        return $this->envelopeProtector()->decrypt($encodedEnvelope, $masterKey);
    }

    public function unprotectEnvelopeWithKeyRing(string $encodedEnvelope, KeyRing|string $keyRing): string
    {
        return $this->envelopeProtector()->decryptWithKeyRing(
            $encodedEnvelope,
            $this->resolveKeyRing($keyRing),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function unprotectString(string $ciphertext, string $key, array $context = []): string
    {
        return $this->stringProtector()->decrypt($ciphertext, $key, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function unprotectStringWithKeyRing(string $ciphertext, KeyRing|string $keyRing, array $context = []): string
    {
        return $this->stringProtector()->decryptWithKeyRing(
            $ciphertext,
            $this->resolveKeyRing($keyRing),
            $context,
        );
    }

    public function unwrapSecret(string $wrappedSecret, string $masterSecret, bool $masterSecretIsBinary = false): string
    {
        return $this->wrappedSecrets()->unwrap($wrappedSecret, $masterSecret, $masterSecretIsBinary);
    }

    public function unwrapSecretWithKeyRing(
        string $wrappedSecret,
        KeyRing|string $keyRing,
        bool $masterSecretsAreBinary = false,
    ): string {
        return $this->wrappedSecrets()->unwrapWithKeyRing(
            $wrappedSecret,
            $this->resolveKeyRing($keyRing),
            $masterSecretsAreBinary,
        );
    }

    public function verifyFileHash(string $path, string $digest, string $key = '', ?string $algorithm = null): bool
    {
        return $this->fileHasher($algorithm)->verify($path, $digest, $key);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function verifyStringHash(string $data, string $digest, array $options = [], ?string $algorithm = null): bool
    {
        return $this->stringHasher($algorithm)->verify($data, $digest, $options);
    }

    public function wrappedSecrets(): WrappedSecretManager
    {
        return new WrappedSecretManager();
    }

    public function wrapSecret(
        string $secret,
        string $masterSecret,
        bool $masterSecretIsBinary = false,
        ?string $keyId = null,
    ): string {
        return $this->wrappedSecrets()->wrap($secret, $masterSecret, $masterSecretIsBinary, $keyId);
    }

    public function wrapSecretWithKeyRing(string $secret, KeyRing|string $keyRing, bool $masterSecretIsBinary = false): string
    {
        return $this->wrappedSecrets()->wrapWithKeyRing(
            $secret,
            $this->resolveKeyRing($keyRing),
            $masterSecretIsBinary,
        );
    }

    protected function configSection(): string
    {
        return 'security';
    }

    private function epicryptPositiveInt(string $path, int $default): int
    {
        return max(1, ValueNormalizer::int($this->config('epicrypt.' . $path), $default));
    }

    private function epicryptProfile(): SecurityProfile
    {
        $profile = ValueNormalizer::nullableString($this->config('epicrypt.profile', SecurityProfile::MODERN->value))
            ?? SecurityProfile::MODERN->value;

        return SecurityProfile::from(strtolower($profile));
    }

    private function epicryptTokenSecret(): string
    {
        return $this->requiredEpicryptSecret('tokens.secret');
    }

    private function epicryptTokenTtl(): int
    {
        return $this->epicryptPositiveInt('tokens.ttl_seconds', 900);
    }

    private function integrityAlgorithm(): string
    {
        return ValueNormalizer::nullableString($this->config('epicrypt.integrity.algorithm', 'sha256')) ?? 'sha256';
    }

    /**
     * @param array<array-key, mixed> $entry
     * @return array{key: string, status?: string, not_before?: int, not_after?: int, purpose?: string}|null
     */
    private function normalizeArrayKeyRingEntry(array $entry): ?array
    {
        $key = $entry['key'] ?? null;
        if (!is_string($key)) {
            return null;
        }

        return ['key' => $key] + $this->normalizeKeyRingEntryMetadata($entry);
    }

    /**
     * @param array<array-key, mixed> $keys
     * @return array<string, string|array{key: string, status?: string, not_before?: int, not_after?: int, purpose?: string}>
     */
    private function normalizeKeyRingEntries(array $keys): array
    {
        $normalized = [];

        foreach ($keys as $keyId => $entry) {
            if (!is_string($keyId) || $keyId === '') {
                continue;
            }

            $normalizedEntry = $this->normalizeKeyRingEntry($entry);
            if ($normalizedEntry === null) {
                continue;
            }

            $normalized[$keyId] = $normalizedEntry;
        }

        return $normalized;
    }

    /**
     * @return string|array{key: string, status?: string, not_before?: int, not_after?: int, purpose?: string}|null
     */
    private function normalizeKeyRingEntry(mixed $entry): string|array|null
    {
        if (is_string($entry)) {
            return $entry;
        }

        if (!is_array($entry)) {
            return null;
        }

        return $this->normalizeArrayKeyRingEntry($entry);
    }

    /**
     * @param array<array-key, mixed> $entry
     * @return array{status?: string, not_before?: int, not_after?: int, purpose?: string}
     */
    private function normalizeKeyRingEntryMetadata(array $entry): array
    {
        $metadata = [];

        $status = $entry['status'] ?? null;
        if (is_string($status)) {
            $metadata['status'] = $status;
        }

        $notBefore = $entry['not_before'] ?? null;
        if (is_int($notBefore)) {
            $metadata['not_before'] = $notBefore;
        }

        $notAfter = $entry['not_after'] ?? null;
        if (is_int($notAfter)) {
            $metadata['not_after'] = $notAfter;
        }

        $purpose = $entry['purpose'] ?? null;
        if (is_string($purpose)) {
            $metadata['purpose'] = $purpose;
        }

        return $metadata;
    }

    private function requiredEpicryptSecret(string $path): string
    {
        $secret = ValueNormalizer::nullableString($this->config('epicrypt.' . $path));
        if ($secret === null) {
            throw new \RuntimeException(sprintf('Security Epicrypt configuration "security.epicrypt.%s" must be a non-empty string.', $path));
        }

        return $secret;
    }

    private function resolveKeyPurpose(KeyPurpose|string $purpose): KeyPurpose
    {
        if ($purpose instanceof KeyPurpose) {
            return $purpose;
        }

        return KeyPurpose::from(strtolower($purpose));
    }

    private function resolveKeyRing(KeyRing|string $keyRing): KeyRing
    {
        return is_string($keyRing) ? $this->keyRing($keyRing) : $keyRing;
    }

    private function signedUrlOptions(): SignedUrlOptions
    {
        $options = ValueNormalizer::associativeArray($this->config('epicrypt.signed_urls.options', []));

        return new SignedUrlOptions(
            method: ValueNormalizer::nullableString($options['method'] ?? null),
            bindHost: ValueNormalizer::bool($options['bind_host'] ?? null, true),
            bindScheme: ValueNormalizer::bool($options['bind_scheme'] ?? null, true),
            allowAbsoluteUrls: ValueNormalizer::bool($options['allow_absolute_urls'] ?? null, true),
            allowRelativeUrls: ValueNormalizer::bool($options['allow_relative_urls'] ?? null, false),
            allowArrayParameters: ValueNormalizer::bool($options['allow_array_parameters'] ?? null, false),
            allowedHosts: ($hosts = ValueNormalizer::stringList($options['allowed_hosts'] ?? null)) === [] ? null : $hosts,
        );
    }
}
