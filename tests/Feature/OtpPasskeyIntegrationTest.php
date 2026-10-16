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

it('routes the selected WebAuthn driver through OTP Passkey', function (): void {
    $app = Foundation::web(foundationOtpPasskeyConfig())->boot();

    expect($app->make(Passkey::class))->toBeInstanceOf(Passkey::class)
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
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Authenticator key coordinates are missing.');
        }
        // COSE EC2/P-256/ES256 map: protocol fixture bytes, consumed by native WebAuthn.
        $publicKey = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20"
            . $details['ec']['x'] . "\x22\x58\x20" . $details['ec']['y'];
        $rawId = random_bytes(32);
        $credentialId = \Infocyph\OTP\Support\Base64Url::encode($rawId);
        $userHandle = hash('sha3-256', "foundation:passkey:user:v1\0owner", true);
        $record = \Webauthn\CredentialRecord::create(
            $rawId, 'public-key', [], 'none', \Webauthn\TrustPath\EmptyTrustPath::create(),
            \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            $publicKey, $userHandle, 0,
        );
        $serializer = new \Webauthn\Denormalizer\WebauthnSerializerFactory(
            \Webauthn\AttestationStatement\AttestationStatementSupportManager::create(),
        )->create();
        $store = $app->make(\Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface::class);
        $store->save(new \Infocyph\Foundation\Auth\Passkey\PasskeyCredential(
            'stored-passkey', 'owner', $credentialId, $serializer->serialize($record, 'json'), '', 0, [], time(),
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
                'authenticatorData' => \Infocyph\OTP\Support\Base64Url::encode($authenticatorData),
                'clientDataJSON' => \Infocyph\OTP\Support\Base64Url::encode($clientData),
                'signature' => \Infocyph\OTP\Support\Base64Url::encode($signature),
                'userHandle' => \Infocyph\OTP\Support\Base64Url::encode($userHandle),
            ],
        ], JSON_THROW_ON_ERROR);
        $result = new \Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult(
            $challenge->id, $credentialId, '', '', '', metadata: ['credential_json' => $payload],
        );
        $services = $app->make(\Infocyph\Foundation\Auth\AuthServices::class);
        $outcome = $services->passkeys()->finishAuthentication($result);
        expect($outcome->successful())->toBeTrue()
            ->and($store->findByCredentialId($credentialId)?->revision)->toBe(1);
        $now = time();
        $session = new \Infocyph\Foundation\Auth\Authentication\Session\AuthSession(
            'passkey-session', 'owner', null, $now, $now, $now + 300, $now,
        );
        $stepUp = $services->stepUp();
        expect($stepUp->requiresStepUp($session, 'payment', ['method' => 'passkey']))->toBeTrue();
        $stepUp->markSatisfied('owner', $session->id, 'payment', \Infocyph\Foundation\Auth\Authentication\StepUp\StepUpMethod::PASSKEY);
        expect($stepUp->requiresStepUp($session, 'payment', ['method' => 'passkey']))->toBeFalse()
            ->and($stepUp->requiresStepUp($session, 'payment', ['method' => 'mfa']))->toBeTrue()
            ->and($stepUp->requiresStepUp(new \Infocyph\Foundation\Auth\Authentication\Session\AuthSession(
                'other-session', 'owner', null, $now, $now, $now + 300, $now,
            ), 'payment', ['method' => 'passkey']))->toBeTrue()
            ->and($services->passkeys()->finishAuthentication($result)->successful())->toBeFalse();
    });
});
