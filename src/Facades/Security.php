<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

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
use Infocyph\Epicrypt\Security\RememberToken;
use Infocyph\Epicrypt\Security\SignedUrl;
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
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Security\SecurityManager;

final class Security extends Facade
{
    public static function accessTokens(): AccessTokenServiceInterface
    {
        return self::manager()->accessTokens();
    }

    public static function actionTokens(): ActionToken
    {
        return self::manager()->actionTokens();
    }

    public static function aeadCipher(): AeadCipher
    {
        return self::manager()->aeadCipher();
    }

    public static function asymmetricJwt(
        RegisteredClaims|ExpectedJwtClaims|null $expectedClaims = null,
        ?string $passphrase = null,
        ?JwtValidationOptions $validationOptions = null,
    ): AsymmetricJwt {
        return self::manager()->asymmetricJwt($expectedClaims, $passphrase, $validationOptions);
    }

    public static function certificateAuthority(): CertificateAuthority
    {
        return self::manager()->certificateAuthority();
    }

    public static function certificateBuilder(string $digestAlgorithm = 'sha512'): CertificateBuilder
    {
        return self::manager()->certificateBuilder($digestAlgorithm);
    }

    public static function certificateChainVerifier(): CertificateChainVerifier
    {
        return self::manager()->certificateChainVerifier();
    }

    public static function certificateExpiry(): CertificateExpiry
    {
        return self::manager()->certificateExpiry();
    }

    public static function certificateFingerprint(): CertificateFingerprint
    {
        return self::manager()->certificateFingerprint();
    }

    public static function certificateKeyMatcher(): CertificateKeyMatcher
    {
        return self::manager()->certificateKeyMatcher();
    }

    public static function certificateParser(): CertificateParser
    {
        return self::manager()->certificateParser();
    }

    public static function csrBuilder(): CsrBuilder
    {
        return self::manager()->csrBuilder();
    }

    public static function csrfTokens(): CsrfTokenManager
    {
        return self::manager()->csrfTokens();
    }

    /**
     * @param array<string, mixed>|KeyDerivationContext $context
     */
    public static function deriveFromPassword(
        string $password,
        string $salt,
        int $length = 32,
        array|KeyDerivationContext $context = [],
    ): string {
        return self::manager()->deriveFromPassword($password, $salt, $length, $context);
    }

    public static function emailVerificationTokens(): EmailVerificationToken
    {
        return self::manager()->emailVerificationTokens();
    }

    public static function envelopeProtector(): EnvelopeProtector
    {
        return self::manager()->envelopeProtector();
    }

    public static function epicryptPasswords(): EpicryptPasswordHasher
    {
        return self::manager()->epicryptPasswords();
    }

    public static function fileHasher(?string $algorithm = null): FileHasher
    {
        return self::manager()->fileHasher($algorithm);
    }

    public static function fileProtector(): FileProtector
    {
        return self::manager()->fileProtector();
    }

    public static function generateKey(int $length = 32, bool $asBase64Url = true): string
    {
        return self::manager()->generateKey($length, $asBase64Url);
    }

    public static function generateKeyForPurpose(KeyPurpose|string $purpose, bool $asBase64Url = true): string
    {
        return self::manager()->generateKeyForPurpose($purpose, $asBase64Url);
    }

    public static function generateMasterSecret(bool $asBase64Url = true): string
    {
        return self::manager()->generateMasterSecret($asBase64Url);
    }

    public static function generateSecretBoxKey(bool $asBase64Url = true): string
    {
        return self::manager()->generateSecretBoxKey($asBase64Url);
    }

    public static function generateSecretStreamKey(bool $asBase64Url = true): string
    {
        return self::manager()->generateSecretStreamKey($asBase64Url);
    }

    public static function hashFile(string $path, string $key = '', ?string $algorithm = null): string
    {
        return self::manager()->hashFile($path, $key, $algorithm);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function hashString(string $data, array $options = [], ?string $algorithm = null): string
    {
        return self::manager()->hashString($data, $options, $algorithm);
    }

    /**
     * @param array<string, mixed>|KeyDerivationContext $context
     */
    public static function hkdf(string $inputKeyMaterial, int $length = 32, array|KeyDerivationContext $context = []): string
    {
        return self::manager()->hkdf($inputKeyMaterial, $length, $context);
    }

    public static function jwks(): Jwks
    {
        return self::manager()->jwks();
    }

    public static function keyDeriver(): KeyDeriver
    {
        return self::manager()->keyDeriver();
    }

    public static function keyExchange(): KeyExchange
    {
        return self::manager()->keyExchange();
    }

    public static function keyGenerator(): KeyMaterialGenerator
    {
        return self::manager()->keyGenerator();
    }

    public static function keyRing(string $name): KeyRing
    {
        return self::manager()->keyRing($name);
    }

    /**
     * @param array<string, string|array{key: string, status?: string, not_before?: int, not_after?: int, purpose?: string}> $keys
     */
    public static function keyRingFromEntries(array $keys, ?string $activeKeyId = null): KeyRing
    {
        return self::manager()->keyRingFromEntries($keys, $activeKeyId);
    }

    public static function keyRotation(): KeyRotationHelper
    {
        return self::manager()->keyRotation();
    }

    public static function mac(): Mac
    {
        return self::manager()->mac();
    }

    public static function manager(): SecurityManager
    {
        return self::app()->security();
    }

    public static function nonces(): NonceGenerator
    {
        return self::manager()->nonces();
    }

    public static function opaqueTokens(): OpaqueToken
    {
        return self::manager()->opaqueTokens();
    }

    public static function openSslKeyPairs(): KeyPairGenerator
    {
        return self::manager()->openSslKeyPairs();
    }

    public static function passwordGenerator(): PasswordGenerator
    {
        return self::manager()->passwordGenerator();
    }

    public static function passwordHasher(): PasswordHasherInterface
    {
        return self::manager()->passwordHasher();
    }

    public static function passwordPolicyValidator(): EpicryptPasswordPolicyValidator
    {
        return self::manager()->passwordPolicyValidator();
    }

    public static function passwordResetTokens(): PasswordResetToken
    {
        return self::manager()->passwordResetTokens();
    }

    public static function passwordStrength(): PasswordStrength
    {
        return self::manager()->passwordStrength();
    }

    public static function passwordVerifier(): PasswordVerifierInterface
    {
        return self::manager()->passwordVerifier();
    }

    public static function pemNormalizer(): PemNormalizer
    {
        return self::manager()->pemNormalizer();
    }

    public static function pkcs12(): Pkcs12
    {
        return self::manager()->pkcs12();
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function protectEnvelope(string $plaintext, string $masterKey, array $context = []): string
    {
        return self::manager()->protectEnvelope($plaintext, $masterKey, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function protectEnvelopeWithKeyRing(string $plaintext, KeyRing|string $keyRing, array $context = []): string
    {
        return self::manager()->protectEnvelopeWithKeyRing($plaintext, $keyRing, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function protectString(string $plaintext, string $key, array $context = []): string
    {
        return self::manager()->protectString($plaintext, $key, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function protectStringWithKeyRing(string $plaintext, KeyRing|string $keyRing, array $context = []): string
    {
        return self::manager()->protectStringWithKeyRing($plaintext, $keyRing, $context);
    }

    public static function publicKeyBoxCipher(): PublicKeyBoxCipher
    {
        return self::manager()->publicKeyBoxCipher();
    }

    public static function randomBytes(): RandomBytesGenerator
    {
        return self::manager()->randomBytes();
    }

    public static function refreshTokens(): RefreshTokenServiceInterface
    {
        return self::manager()->refreshTokens();
    }

    public static function rememberTokens(): RememberToken
    {
        return self::manager()->rememberTokens();
    }

    public static function rsaCipher(): RsaCipher
    {
        return self::manager()->rsaCipher();
    }

    public static function salts(): SaltGenerator
    {
        return self::manager()->salts();
    }

    public static function sealedBoxCipher(): SealedBoxCipher
    {
        return self::manager()->sealedBoxCipher();
    }

    public static function secretBoxCipher(): SecretBoxCipher
    {
        return self::manager()->secretBoxCipher();
    }

    public static function secretStream(string $key): SecretStream
    {
        return self::manager()->secretStream($key);
    }

    public static function signature(): Signature
    {
        return self::manager()->signature();
    }

    public static function signedPayload(?string $context = null): SignedPayload
    {
        return self::manager()->signedPayload($context);
    }

    public static function signedUrls(): SignedUrl
    {
        return self::manager()->signedUrls();
    }

    public static function sodiumBoxKeyPairs(): KeyPairGenerator
    {
        return self::manager()->sodiumBoxKeyPairs();
    }

    public static function sodiumSigningKeyPairs(): KeyPairGenerator
    {
        return self::manager()->sodiumSigningKeyPairs();
    }

    public static function stringHasher(?string $algorithm = null): StringHasher
    {
        return self::manager()->stringHasher($algorithm);
    }

    public static function stringProtector(): StringProtector
    {
        return self::manager()->stringProtector();
    }

    /**
     * @param array<string, mixed>|KeyDerivationContext $context
     */
    public static function subkey(
        string $rootKey,
        int $subkeyId,
        int $length = 32,
        array|KeyDerivationContext $context = [],
    ): string {
        return self::manager()->subkey($rootKey, $subkeyId, $length, $context);
    }

    public static function symmetricJwt(
        RegisteredClaims|ExpectedJwtClaims|null $expectedClaims = null,
        ?JwtValidationOptions $validationOptions = null,
    ): SymmetricJwt {
        return self::manager()->symmetricJwt($expectedClaims, $validationOptions);
    }

    public static function tokenMaterial(): TokenMaterialGenerator
    {
        return self::manager()->tokenMaterial();
    }

    public static function unprotectEnvelope(string $encodedEnvelope, string $masterKey): string
    {
        return self::manager()->unprotectEnvelope($encodedEnvelope, $masterKey);
    }

    public static function unprotectEnvelopeWithKeyRing(string $encodedEnvelope, KeyRing|string $keyRing): string
    {
        return self::manager()->unprotectEnvelopeWithKeyRing($encodedEnvelope, $keyRing);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function unprotectString(string $ciphertext, string $key, array $context = []): string
    {
        return self::manager()->unprotectString($ciphertext, $key, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function unprotectStringWithKeyRing(string $ciphertext, KeyRing|string $keyRing, array $context = []): string
    {
        return self::manager()->unprotectStringWithKeyRing($ciphertext, $keyRing, $context);
    }

    public static function unwrapSecret(string $wrappedSecret, string $masterSecret, bool $masterSecretIsBinary = false): string
    {
        return self::manager()->unwrapSecret($wrappedSecret, $masterSecret, $masterSecretIsBinary);
    }

    public static function unwrapSecretWithKeyRing(
        string $wrappedSecret,
        KeyRing|string $keyRing,
        bool $masterSecretsAreBinary = false,
    ): string {
        return self::manager()->unwrapSecretWithKeyRing($wrappedSecret, $keyRing, $masterSecretsAreBinary);
    }

    public static function verifyFileHash(string $path, string $digest, string $key = '', ?string $algorithm = null): bool
    {
        return self::manager()->verifyFileHash($path, $digest, $key, $algorithm);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function verifyStringHash(string $data, string $digest, array $options = [], ?string $algorithm = null): bool
    {
        return self::manager()->verifyStringHash($data, $digest, $options, $algorithm);
    }

    public static function wrappedSecrets(): WrappedSecretManager
    {
        return self::manager()->wrappedSecrets();
    }

    public static function wrapSecret(
        string $secret,
        string $masterSecret,
        bool $masterSecretIsBinary = false,
        ?string $keyId = null,
    ): string {
        return self::manager()->wrapSecret($secret, $masterSecret, $masterSecretIsBinary, $keyId);
    }

    public static function wrapSecretWithKeyRing(string $secret, KeyRing|string $keyRing, bool $masterSecretIsBinary = false): string
    {
        return self::manager()->wrapSecretWithKeyRing($secret, $keyRing, $masterSecretIsBinary);
    }
}
