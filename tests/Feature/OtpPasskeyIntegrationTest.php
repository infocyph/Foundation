<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Adapter\Otp\OtpPasskeyService;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Exception\ServiceResolutionException;
use Infocyph\Foundation\Foundation;
use Infocyph\OTP\Passkey;

/** @return array<string,mixed> */
function foundationOtpPasskeyConfig(string $storeDriver = 'memory'): array
{
    return [
        'auth' => [
            'drivers' => [
                'cache' => 'cache',
                'passkey' => 'webauthn',
                'storage' => 'memory',
            ],
            'passkey' => [
                'state' => ['store' => 'passkey-state'],
            ],
            'webauthn' => [
                'rp_id' => 'foundation.test',
                'origin' => 'https://foundation.test',
                'challenge_ttl' => 300,
            ],
        ],
        'cache' => [
            'default' => 'passkey-state',
            'stores' => [
                'passkey-state' => [
                    'driver' => $storeDriver,
                    'namespace' => 'foundation-test-passkey-' . bin2hex(random_bytes(8)),
                    'fail_open' => false,
                    'security' => [
                        'integrity_key' => 'foundation-test-passkey-integrity-key',
                    ],
                ],
            ],
        ],
    ];
}

/** Encode disposable authenticator bytes without internal OTP APIs. */
function foundationWebauthnBase64Url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * OpenSSL's P-256 coordinate buffers can omit leading zero bytes; COSE EC2
 * requires each coordinate to occupy exactly 32 bytes.
 * @throws \InvalidArgumentException
 */
function foundationCoseP256Coordinate(string $coordinate): string
{
    if ($coordinate === '' || strlen($coordinate) > 32) {
        throw new InvalidArgumentException('P-256 coordinate has invalid size.');
    }

    return str_pad($coordinate, 32, "\0", STR_PAD_LEFT);
}

it('encodes P-256 coordinates as fixed-width COSE bytes even with leading zeroes', function (): void {
    $short = str_repeat("\x01", 31);
    $padded = foundationCoseP256Coordinate($short);

    expect(strlen($padded))->toBe(32)
        ->and(substr($padded, 0, 1))->toBe("\0")
        ->and(substr($padded, 1))->toBe($short)
        ->and(fn() => foundationCoseP256Coordinate(str_repeat('x', 33)))
        ->toThrow(InvalidArgumentException::class);
});

it('routes the selected WebAuthn driver through OTP Passkey', function (): void {
    $app = Foundation::web(foundationOtpPasskeyConfig())->boot();

    expect($app->make(Passkey::class))->toBe($app->make(Passkey::class))
        ->and($app->make(PasskeyServiceInterface::class))->toBeInstanceOf(OtpPasskeyService::class);
});

it('fails closed when passkey ceremony storage lacks authentication-state capability', function (): void {
    $app = Foundation::web(foundationOtpPasskeyConfig('null_store'))->boot();

    expect(fn() => $app->make(Passkey::class))
        ->toThrow(
            ServiceResolutionException::class,
            'Authentication state caches must use one authoritative direct backend.',
        );
});

it('verifies a signed native WebAuthn assertion before accepting method-bound step-up', function (): void {
    $app = Foundation::web(foundationOtpPasskeyConfig())->boot();
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new RuntimeException('Unable to create the disposable authenticator key.');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !is_array($details['ec'] ?? null)
            || !is_string($details['ec']['x'] ?? null) || !is_string($details['ec']['y'] ?? null)) {
            throw new RuntimeException('Authenticator key coordinates are missing.');
        }
        // COSE EC2/P-256/ES256 map: protocol fixture bytes, consumed by native WebAuthn.
        $publicKey = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20"
            . foundationCoseP256Coordinate($details['ec']['x'])
            . "\x22\x58\x20" . foundationCoseP256Coordinate($details['ec']['y']);
        $rawId = random_bytes(32);
        $credentialId = foundationWebauthnBase64Url($rawId);
        $userHandle = hash('sha3-256', "foundation:passkey:user:v1\0owner", true);
        $record = \Webauthn\CredentialRecord::create(
            $rawId,
            'public-key',
            [],
            'none',
            \Webauthn\TrustPath\EmptyTrustPath::create(),
            \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            $publicKey,
            $userHandle,
            0,
        );
        $serializer = new \Webauthn\Denormalizer\WebauthnSerializerFactory(
            \Webauthn\AttestationStatement\AttestationStatementSupportManager::create(),
        )->create();
        $store = $app->make(\Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface::class);
        $store->save(new \Infocyph\Foundation\Auth\Passkey\PasskeyCredential(
            'stored-passkey',
            'owner',
            $credentialId,
            $serializer->serialize($record, 'json'),
            '',
            0,
            [],
            time(),
        ));
        $service = $app->make(PasskeyServiceInterface::class);
        $challenge = $service->startAuthentication('owner');
        $clientData = json_encode([
            'type' => 'webauthn.get', 'challenge' => $challenge->challenge, 'origin' => 'https://foundation.test',
        ], JSON_THROW_ON_ERROR);
        $authenticatorData = hash('sha256', 'foundation.test', true) . "\x05" . pack('N', 1);
        $signature = '';
        if (!openssl_sign($authenticatorData . hash('sha256', $clientData, true), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the authenticator assertion.');
        }
        $payload = json_encode([
            'id' => $credentialId, 'rawId' => $credentialId, 'type' => 'public-key',
            'response' => [
                'authenticatorData' => foundationWebauthnBase64Url($authenticatorData),
                'clientDataJSON' => foundationWebauthnBase64Url($clientData),
                'signature' => foundationWebauthnBase64Url(foundationFixtureString($signature)),
                'userHandle' => foundationWebauthnBase64Url($userHandle),
            ],
        ], JSON_THROW_ON_ERROR);
        $result = new \Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult(
            $challenge->id,
            $credentialId,
            '',
            '',
            '',
            metadata: ['credential_json' => $payload],
        );
        $services = $app->make(\Infocyph\Foundation\Auth\AuthServices::class);
        $outcome = $services->passkeys()->finishAuthentication($result);
        expect($outcome->successful())->toBeTrue()
            ->and($store->findByCredentialId($credentialId)?->revision)->toBe(1);
        $now = time();
        $session = new \Infocyph\Foundation\Auth\Authentication\Session\AuthSession(
            'passkey-session',
            'owner',
            null,
            $now,
            $now,
            $now + 300,
            $now,
        );
        $stepUp = $services->stepUp();
        expect($stepUp->requiresStepUp($session, 'payment', ['method' => 'passkey']))->toBeTrue();
        $stepUp->markSatisfied('owner', $session->id, 'payment', \Infocyph\Foundation\Auth\Authentication\StepUp\StepUpMethod::PASSKEY);
        expect($stepUp->requiresStepUp($session, 'payment', ['method' => 'passkey']))->toBeFalse()
            ->and($stepUp->requiresStepUp($session, 'payment', ['method' => 'mfa']))->toBeTrue()
            ->and($stepUp->requiresStepUp(new \Infocyph\Foundation\Auth\Authentication\Session\AuthSession(
                'other-session',
                'owner',
                null,
                $now,
                $now,
                $now + 300,
                $now,
            ), 'payment', ['method' => 'passkey']))->toBeTrue()
            ->and($services->passkeys()->finishAuthentication($result)->successful())->toBeFalse();

        // A fresh, correctly signed authenticator assertion is still invalid
        // when its browser origin does not match the configured RP policy.
        $wrongOriginChallenge = $service->startAuthentication('owner');
        $wrongOriginClientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $wrongOriginChallenge->challenge,
            'origin' => 'https://untrusted.example.test',
        ], JSON_THROW_ON_ERROR);
        $wrongOriginAuthenticatorData = hash('sha256', 'foundation.test', true) . "\x05" . pack('N', 2);
        $wrongOriginSignature = '';
        if (!openssl_sign(
            $wrongOriginAuthenticatorData . hash('sha256', $wrongOriginClientData, true),
            $wrongOriginSignature,
            $key,
            OPENSSL_ALGO_SHA256,
        )) {
            throw new RuntimeException('Unable to sign the wrong-origin authenticator fixture.');
        }
        $wrongOriginPayload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($wrongOriginPayload)) {
            throw new \UnexpectedValueException('Expected a decoded JSON object.');
        }
        \PHPUnit\Framework\Assert::assertIsArray(($wrongOriginPayload['response'] ?? null));
        $wrongOriginPayload['response']['authenticatorData'] = foundationWebauthnBase64Url($wrongOriginAuthenticatorData);
        $wrongOriginPayload['response']['clientDataJSON'] = foundationWebauthnBase64Url($wrongOriginClientData);
        $wrongOriginPayload['response']['signature'] = foundationWebauthnBase64Url(foundationFixtureString($wrongOriginSignature));
        $wrongOriginResult = new \Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult(
            $wrongOriginChallenge->id,
            $credentialId,
            '',
            '',
            '',
            metadata: ['credential_json' => json_encode($wrongOriginPayload, JSON_THROW_ON_ERROR)],
        );
        expect($services->passkeys()->finishAuthentication($wrongOriginResult)->successful())->toBeFalse()
            ->and($store->findByCredentialId($credentialId)?->revision)->toBe(1);

        // A valid signature over a different RP ID hash must also fail without
        // consuming a fresh ceremony or altering the stored credential revision.
        $wrongRpChallenge = $service->startAuthentication('owner');
        $wrongRpClientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $wrongRpChallenge->challenge,
            'origin' => 'https://foundation.test',
        ], JSON_THROW_ON_ERROR);
        $wrongRpAuthenticatorData = hash('sha256', 'another-rp.example.test', true) . "\x05" . pack('N', 2);
        $wrongRpSignature = '';
        if (!openssl_sign(
            $wrongRpAuthenticatorData . hash('sha256', $wrongRpClientData, true),
            $wrongRpSignature,
            $key,
            OPENSSL_ALGO_SHA256,
        )) {
            throw new RuntimeException('Unable to sign the wrong-RP authenticator fixture.');
        }

        $wrongRpPayload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        \PHPUnit\Framework\Assert::assertIsArray($wrongRpPayload);
        \PHPUnit\Framework\Assert::assertIsArray(($wrongRpPayload['response'] ?? null));

        $wrongRpPayload['response']['authenticatorData'] = foundationWebauthnBase64Url($wrongRpAuthenticatorData);
        $wrongRpPayload['response']['clientDataJSON'] = foundationWebauthnBase64Url($wrongRpClientData);
        $wrongRpPayload['response']['signature'] = foundationWebauthnBase64Url(foundationFixtureString($wrongRpSignature));
        $wrongRpResult = new \Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult(
            $wrongRpChallenge->id,
            $credentialId,
            '',
            '',
            '',
            metadata: ['credential_json' => json_encode($wrongRpPayload, JSON_THROW_ON_ERROR)],
        );
        expect($services->passkeys()->finishAuthentication($wrongRpResult)->successful())->toBeFalse()
            ->and($store->findByCredentialId($credentialId)?->revision)->toBe(1);
    });
});

it('registers an actual none-attested WebAuthn credential and signs its first assertion through Foundation', function (): void {
    $app = Foundation::web(foundationOtpPasskeyConfig())->boot();

    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $services = $app->make(\Infocyph\Foundation\Auth\AuthServices::class);
        $account = $services->accounts()->create('native-passkey-registration@example.test')->account;
        if (!$account instanceof \Infocyph\Foundation\Auth\Account\AccountInterface) {
            throw new RuntimeException('Passkey account creation failed.');
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new RuntimeException('Unable to generate the test authenticator key.');
        }
        $details = openssl_pkey_get_details($key);
        \PHPUnit\Framework\Assert::assertIsArray(($details['ec'] ?? null));
        if (!isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Authenticator EC coordinates are unavailable.');
        }

        // Actual COSE EC2/P-256 public key with user presence, verification,
        // and attested-credential-data flags. No production CBOR engine is added.
        $coseKey = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20"
            . foundationCoseP256Coordinate(foundationFixtureString($details['ec']['x']))
            . "\x22\x58\x20" . foundationCoseP256Coordinate(foundationFixtureString($details['ec']['y']));
        $credentialIdBytes = random_bytes(32);
        $credentialId = foundationWebauthnBase64Url($credentialIdBytes);
        $registration = $services->passkeys()->startRegistration($account->id())->challenge;
        if (!$registration instanceof \Infocyph\Foundation\Auth\Passkey\PasskeyChallenge) {
            throw new RuntimeException('Native WebAuthn registration ceremony was not issued.');
        }

        $attestedData = hash('sha256', 'foundation.test', true)
            . "\x45" . pack('N', 0)
            . str_repeat("\0", 16)
            . pack('n', strlen($credentialIdBytes))
            . $credentialIdBytes
            . $coseKey;
        if (strlen($attestedData) > 255) {
            throw new RuntimeException('Unexpected attested-data size.');
        }
        $attestation = "\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData\x58"
            . chr(strlen($attestedData))
            . $attestedData;
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $registration->challenge,
            'origin' => 'https://foundation.test',
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
        $browserCredential = json_encode([
            'id' => $credentialId,
            'rawId' => $credentialId,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => foundationWebauthnBase64Url($clientData),
                'attestationObject' => foundationWebauthnBase64Url($attestation),
                'transports' => ['internal'],
            ],
        ], JSON_THROW_ON_ERROR);
        $registrationResult = new \Infocyph\Foundation\Auth\Passkey\PasskeyRegistrationResult(
            challengeId: $registration->id,
            accountId: $account->id(),
            credentialId: $credentialId,
            publicKey: '',
            transports: ['internal'],
            metadata: ['credential_json' => $browserCredential],
        );
        $registered = $services->passkeys()->finishRegistration($registrationResult);
        $store = $app->make(\Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface::class);
        $saved = $store->findByCredentialId($credentialId);

        expect($registered->successful())->toBeTrue()
            ->and($saved)->not->toBeNull()
            ->and($saved?->accountId)->toBe($account->id())
            ->and($saved?->credentialId)->toBe($credentialId)
            ->and($saved?->credentialRecordJson)->toBeString()->not->toBeEmpty()
            ->and($saved?->revision)->toBe(0);
        expect(fn() => $services->passkeys()->finishRegistration($registrationResult))
            ->toThrow(RuntimeException::class);

        $authentication = $services->passkeys()->startAuthentication($account->id())->challenge;
        if (!$authentication instanceof \Infocyph\Foundation\Auth\Passkey\PasskeyChallenge) {
            throw new RuntimeException('Native WebAuthn assertion ceremony was not issued.');
        }
        $assertionClientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $authentication->challenge,
            'origin' => 'https://foundation.test',
        ], JSON_THROW_ON_ERROR);
        $authenticatorData = hash('sha256', 'foundation.test', true) . "\x05" . pack('N', 1);
        $signature = '';
        if (!openssl_sign(
            $authenticatorData . hash('sha256', $assertionClientData, true),
            $signature,
            $key,
            OPENSSL_ALGO_SHA256,
        )) {
            throw new RuntimeException('Unable to sign native WebAuthn assertion.');
        }
        $assertion = json_encode([
            'id' => $credentialId,
            'rawId' => $credentialId,
            'type' => 'public-key',
            'response' => [
                'authenticatorData' => foundationWebauthnBase64Url($authenticatorData),
                'clientDataJSON' => foundationWebauthnBase64Url($assertionClientData),
                'signature' => foundationWebauthnBase64Url(foundationFixtureString($signature)),
                'userHandle' => foundationWebauthnBase64Url(
                    hash('sha3-256', "foundation:passkey:user:v1\0" . $account->id(), true),
                ),
            ],
        ], JSON_THROW_ON_ERROR);
        $result = new \Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult(
            challengeId: $authentication->id,
            credentialId: $credentialId,
            clientData: '',
            authenticatorData: '',
            signature: '',
            metadata: ['credential_json' => $assertion],
        );
        expect($services->passkeys()->finishAuthentication($result)->successful())->toBeTrue()
            ->and($store->findByCredentialId($credentialId)?->revision)->toBe(1)
            ->and($services->passkeys()->finishAuthentication($result)->successful())->toBeFalse()
            ->and($store->findByCredentialId($credentialId)?->revision)->toBe(1);
    });
});
