<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Generate\KeyMaterial\Enum\KeyPurpose;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator;
use Infocyph\Foundation\Facades\Security;
use Infocyph\Foundation\Foundation;

it('surfaces Epicrypt data protection, key derivation, and integrity helpers through Foundation security', function (): void {
    $generator = new KeyMaterialGenerator();
    $previousDataKey = $generator->forSecretBox();
    $currentDataKey = $generator->forSecretBox();
    $previousSecretKey = $generator->forMasterSecret();
    $currentSecretKey = $generator->forMasterSecret();
    $streamKey = $generator->forSecretStream();
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);

    $basePath = sys_get_temp_dir() . '/foundation-epicrypt-' . uniqid('', true);
    mkdir($basePath . '/storage/cache', 0775, true);
    mkdir($basePath . '/storage/files', 0775, true);

    $plainPath = $basePath . '/storage/files/plain.txt';
    $encryptedPath = $basePath . '/storage/files/plain.txt.epc';
    $decryptedPath = $basePath . '/storage/files/plain.dec.txt';
    file_put_contents($plainPath, 'file-protection-payload');

    Foundation::create([
        'app' => [
            'base_path' => $basePath,
        ],
        'security' => [
            'epicrypt' => [
                'integrity' => [
                    'algorithm' => 'sha256',
                ],
                'key_rings' => [
                    'data' => [
                        'active' => 'current',
                        'keys' => [
                            'previous' => $previousDataKey,
                            'current' => $currentDataKey,
                        ],
                    ],
                    'secrets' => [
                        'active' => 'current',
                        'keys' => [
                            'previous' => $previousSecretKey,
                            'current' => $currentSecretKey,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    try {
        $generatedMaster = Security::generateKeyForPurpose(KeyPurpose::MASTER_SECRET);
        $derived = Security::deriveFromPassword('MyStrongPassword!2026', $salt, 32, [
            'salt_is_binary' => true,
        ]);
        $hkdf = Security::hkdf($generatedMaster, 32, [
            'info' => 'foundation:test',
            'salt' => Security::generateKey(16),
        ]);

        $legacyCiphertext = Security::stringProtector()->encrypt('rotating payload', $previousDataKey);
        $legacyResult = Security::stringProtector()->decryptWithKeyRingResult($legacyCiphertext, Security::keyRing('data'));
        $ciphertext = Security::protectStringWithKeyRing('protected payload', 'data', ['tenant' => 'acme']);
        $plaintext = Security::unprotectStringWithKeyRing($ciphertext, 'data', ['tenant' => 'acme']);

        $envelope = Security::protectEnvelopeWithKeyRing('enveloped payload', 'data', ['purpose' => 'merchant.secret']);
        $envelopePlaintext = Security::unprotectEnvelopeWithKeyRing($envelope, 'data');
        $envelopeInfo = Security::envelopeProtector()->inspect($envelope);

        $wrapped = Security::wrapSecretWithKeyRing('top-secret-value', 'secrets');
        $unwrapped = Security::unwrapSecretWithKeyRing($wrapped, 'secrets');

        $fileDigest = Security::hashFile($plainPath);
        $stringDigest = Security::hashString('payload', ['key' => 'mac-secret']);

        Security::fileProtector()->encrypt($plainPath, $encryptedPath, $streamKey);
        Security::fileProtector()->decrypt($encryptedPath, $decryptedPath, $streamKey);

        expect(Security::keyRing('data')->activeKeyId())->toBe('current')
            ->and($generatedMaster)->not->toBe('')
            ->and($derived)->not->toBe('')
            ->and($hkdf)->not->toBe('')
            ->and($legacyResult->plaintext)->toBe('rotating payload')
            ->and($legacyResult->matchedKeyId)->toBe('previous')
            ->and($legacyResult->usedFallbackKey)->toBeTrue()
            ->and($plaintext)->toBe('protected payload')
            ->and($envelopePlaintext)->toBe('enveloped payload')
            ->and($envelopeInfo->keyId)->toBe('current')
            ->and($envelopeInfo->purpose)->toBe('merchant.secret')
            ->and($unwrapped)->toBe('top-secret-value')
            ->and(Security::verifyFileHash($plainPath, $fileDigest))->toBeTrue()
            ->and(Security::verifyStringHash('payload', $stringDigest, ['key' => 'mac-secret']))->toBeTrue()
            ->and(file_get_contents($decryptedPath))->toBe('file-protection-payload');
    } finally {
        foundationEpicryptRemoveDirectory($basePath);
    }
});

it('surfaces Epicrypt security, crypto, token, generator, and certificate services through Foundation security', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-epicrypt-services-' . uniqid('', true);
    mkdir($basePath . '/storage/cache', 0775, true);
    mkdir($basePath . '/storage/files', 0775, true);

    $plainPath = $basePath . '/storage/files/stream.txt';
    $encryptedPath = $basePath . '/storage/files/stream.txt.epc';
    $decryptedPath = $basePath . '/storage/files/stream.dec.txt';
    file_put_contents($plainPath, 'secret-stream-payload');

    Foundation::create([
        'app' => [
            'base_path' => $basePath,
        ],
        'security' => [
            'epicrypt' => [
                'csrf' => [
                    'secret' => 'csrf-test-secret',
                    'ttl_seconds' => 60,
                ],
                'signed_urls' => [
                    'secret' => 'url-test-secret',
                    'options' => [
                        'allowed_hosts' => ['example.test'],
                    ],
                ],
                'tokens' => [
                    'secret' => 'token-test-secret',
                    'ttl_seconds' => 60,
                ],
            ],
        ],
    ]);

    try {
        $aeadKey = Security::generateKeyForPurpose(KeyPurpose::AEAD);
        $aeadCiphertext = Security::aeadCipher()->encrypt('aead-payload', $aeadKey, ['aad' => 'merchant:1']);
        $macKey = Security::mac()->generateKey();
        $mac = Security::mac()->generate('mac-payload', $macKey);
        $signingKeys = Security::sodiumSigningKeyPairs()->generate(asBase64Url: true);
        $signature = Security::signature()->sign('signed-payload', $signingKeys['private']);

        $sender = Security::sodiumBoxKeyPairs()->generate();
        $recipient = Security::sodiumBoxKeyPairs()->generate();
        $sealed = Security::sealedBoxCipher()->encrypt('sealed-payload', $recipient['public'], ['key_is_binary' => true]);
        $recipientKeyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($recipient['private'], $recipient['public']);
        $sealedPlaintext = Security::sealedBoxCipher()->decrypt($sealed, $recipientKeyPair, ['key_is_binary' => true]);
        $boxed = Security::publicKeyBoxCipher()->encrypt('box-payload', [
            'recipient_public' => $recipient['public'],
            'sender_private' => $sender['private'],
        ], ['key_is_binary' => true]);
        $boxedPlaintext = Security::publicKeyBoxCipher()->decrypt($boxed, [
            'recipient_private' => $recipient['private'],
            'sender_public' => $sender['public'],
        ], ['key_is_binary' => true]);

        $streamKey = Security::generateSecretStreamKey(false);
        Security::secretStream($streamKey)->encrypt($plainPath, $encryptedPath);
        Security::secretStream($streamKey)->decrypt($encryptedPath, $decryptedPath);

        $csrfToken = Security::csrfTokens()->issueToken('session-1');
        $signedUrl = Security::signedUrls()->generate('https://example.test/download', ['file' => 'report.pdf'], time() + 60);
        $actionToken = Security::actionTokens()->issue('user-1', 'delete-account');
        $emailToken = Security::emailVerificationTokens()->issue('user-1', 'user@example.test');
        $resetToken = Security::passwordResetTokens()->issue('user-1');
        $rememberToken = Security::rememberTokens()->issue('user-1', 'device-1');

        $opaqueToken = Security::opaqueTokens()->issue();
        $opaqueDigest = Security::opaqueTokens()->hash($opaqueToken);
        $payloadToken = Security::signedPayload('merchant.action')->encode(
            ['sub' => 'merchant-1'],
            'payload-test-secret',
            ['exp' => time() + 60],
        );
        $jwt = Security::symmetricJwt()->encode([
            'iss' => 'foundation',
            'aud' => 'foundation-api',
            'sub' => 'user-1',
            'nbf' => time(),
            'exp' => time() + 60,
        ], 'jwt-test-secret');
        $jwtVerifier = Security::symmetricJwt(
            new Infocyph\Epicrypt\Token\Jwt\Validation\RegisteredClaims('foundation', 'foundation-api', 'user-1'),
        );

        $rotationRing = Security::keyRingFromEntries([
            'previous' => 'previous-rotation-secret',
            'active' => 'active-rotation-secret',
        ], 'active');
        $rotationSignature = Security::keyRotation()->signWithKeyRing('rotation-payload', $rotationRing);

        $password = Security::passwordGenerator()->generate(20);
        $passwordHash = Security::epicryptPasswords()->hashPassword($password);
        $passwordPolicy = Security::passwordPolicyValidator()->validate($password);

        $exchangeA = Security::sodiumBoxKeyPairs()->generate(asBase64Url: true);
        $exchangeB = Security::sodiumBoxKeyPairs()->generate(asBase64Url: true);
        $exchangeSecretA = Security::keyExchange()->derive($exchangeA['private'], $exchangeB['public']);
        $exchangeSecretB = Security::keyExchange()->derive($exchangeB['private'], $exchangeA['public']);

        $certificateKeys = Security::openSslKeyPairs()->generate();
        $certificate = Security::certificateBuilder()->selfSign([
            'commonName' => 'foundation.epicrypt.test',
        ], $certificateKeys['private']);
        $certificateInfo = Security::certificateParser()->parse($certificate);

        expect(Security::aeadCipher()->decrypt($aeadCiphertext, $aeadKey, ['aad' => 'merchant:1']))->toBe('aead-payload')
            ->and(Security::mac()->verify('mac-payload', $mac, $macKey))->toBeTrue()
            ->and(Security::signature()->verify('signed-payload', $signature, $signingKeys['public']))->toBeTrue()
            ->and($sealedPlaintext)->toBe('sealed-payload')
            ->and($boxedPlaintext)->toBe('box-payload')
            ->and(file_get_contents($decryptedPath))->toBe('secret-stream-payload')
            ->and(Security::randomBytes()->string(24, 'id_', '_ok'))->toStartWith('id_')
            ->and(Security::nonces()->generate())->not->toBe('')
            ->and(Security::salts()->generate())->not->toBe('')
            ->and(Security::tokenMaterial()->generate())->not->toBe('')
            ->and(Security::csrfTokens()->verifyToken('session-1', $csrfToken))->toBeTrue()
            ->and(Security::signedUrls()->verify($signedUrl))->toBeTrue()
            ->and(Security::actionTokens()->verify($actionToken, 'user-1', 'delete-account'))->toBeTrue()
            ->and(Security::emailVerificationTokens()->verify($emailToken, 'user@example.test'))->toBeTrue()
            ->and(Security::passwordResetTokens()->verify($resetToken, 'user-1'))->toBeTrue()
            ->and(Security::rememberTokens()->verify($rememberToken, 'user-1', 'device-1'))->toBeTrue()
            ->and(Security::opaqueTokens()->verify($opaqueToken, $opaqueDigest))->toBeTrue()
            ->and(Security::signedPayload('merchant.action')->verify($payloadToken, 'payload-test-secret'))->toBeTrue()
            ->and($jwtVerifier->verify($jwt, 'jwt-test-secret'))->toBeTrue()
            ->and(Security::keyRotation()->verify('rotation-payload', $rotationSignature, $rotationRing))->toBeTrue()
            ->and(Security::epicryptPasswords()->verifyPassword($password, $passwordHash))->toBeTrue()
            ->and($passwordPolicy->valid)->toBeTrue()
            ->and(Security::passwordStrength()->score($password))->toBeGreaterThan(0)
            ->and($exchangeSecretA)->toBe($exchangeSecretB)
            ->and(Security::certificateKeyMatcher()->privateKeyMatches($certificate, $certificateKeys['private']))->toBeTrue()
            ->and(Security::certificateFingerprint()->fingerprint($certificate))->toHaveLength(64)
            ->and(Security::certificateExpiry()->isExpired($certificate))->toBeFalse()
            ->and($certificateInfo['subject']['CN'] ?? $certificateInfo['subject']['commonName'] ?? null)->toBe('foundation.epicrypt.test')
            ->and(Security::pemNormalizer()->normalize($certificate))->toContain('BEGIN CERTIFICATE')
            ->and(Security::certificateChainVerifier())->toBeInstanceOf(Infocyph\Epicrypt\Certificate\CertificateChainVerifier::class)
            ->and(Security::certificateAuthority())->toBeInstanceOf(Infocyph\Epicrypt\Certificate\CertificateAuthority::class)
            ->and(Security::csrBuilder())->toBeInstanceOf(Infocyph\Epicrypt\Certificate\CsrBuilder::class)
            ->and(Security::rsaCipher())->toBeInstanceOf(Infocyph\Epicrypt\Certificate\OpenSSL\RsaCipher::class)
            ->and(Security::pkcs12())->toBeInstanceOf(Infocyph\Epicrypt\Certificate\Pkcs12::class)
            ->and(Security::asymmetricJwt())->toBeInstanceOf(Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt::class)
            ->and(Security::jwks())->toBeInstanceOf(Infocyph\Epicrypt\Token\Jwt\Jwks::class);
    } finally {
        foundationEpicryptRemoveDirectory($basePath);
    }
});

function foundationEpicryptRemoveDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $target = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($target)) {
            foundationEpicryptRemoveDirectory($target);

            continue;
        }

        unlink($target);
    }

    rmdir($path);
}
