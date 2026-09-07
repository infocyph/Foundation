<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Adapter\Otp\OtpPasskeyService;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
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
                    'namespace' => 'foundation-test-passkey-' . uniqid('', true),
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
        ->toThrow(LogicException::class, 'Authentication state caches must use one authoritative direct backend.');
});
