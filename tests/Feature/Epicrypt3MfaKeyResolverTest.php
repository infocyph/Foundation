<?php

declare(strict_types=1);

use Infocyph\Epicrypt\DataProtection\ProtectionAlgorithm;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Foundation\Auth\Internal\AuthMfaKeyResolver;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

it('resolves production recovery and MFA keys from independent environment locators', function (): void {
    $mfaEnvironment = 'FOUNDATION_TEST_MFA_KEY';
    $recoveryEnvironment = 'FOUNDATION_TEST_RECOVERY_KEY';
    $mfaKey = (new KeyMaterialGenerator())->forAead();
    $recoveryMaster = str_repeat('r', 32);

    $_ENV[$mfaEnvironment] = $mfaKey;
    $_ENV[$recoveryEnvironment] = $recoveryMaster;

    try {
        $config = new ConfigRepository([
            'app' => ['env' => 'production'],
            'auth' => [
                'otp' => [
                    'recovery_codes' => [
                        'hmac_key_environment' => $recoveryEnvironment,
                    ],
                    'secret_protection' => [
                        'allow_legacy_plaintext' => false,
                        'keys' => [[
                            'id' => 'mfa-2026-09',
                            'environment' => $mfaEnvironment,
                            'status' => 'active',
                        ]],
                    ],
                ],
            ],
        ]);
        $resolver = new AuthMfaKeyResolver($config);
        $resolver->assertProductionReady();

        $active = $resolver->mfaSecretKeyRing()->activeForWrite(
            KeyPurpose::DATA_PROTECTION,
            ProtectionAlgorithm::XCHACHA20_POLY1305->value,
        );
        $recoveryKey = $resolver->recoveryHmacKey();

        expect($active->id)->toBe('mfa-2026-09')
            ->and($active->key)->toBe($mfaKey)
            ->and(strlen($recoveryKey))->toBe(32)
            ->and($recoveryKey)->not->toBe($recoveryMaster)
            ->and($resolver->allowLegacyPlaintext())->toBeFalse();
    } finally {
        unset($_ENV[$mfaEnvironment], $_ENV[$recoveryEnvironment]);
    }
});

it('fails production key readiness without explicit independent secret material', function (): void {
    $config = new ConfigRepository([
        'app' => ['env' => 'production'],
        'auth' => [
            'otp' => [
                'recovery_codes' => [
                    'hmac_key_environment' => 'FOUNDATION_TEST_MISSING_RECOVERY_KEY',
                ],
                'secret_protection' => [
                    'keys' => [],
                ],
            ],
        ],
    ]);

    expect(fn() => (new AuthMfaKeyResolver($config))->assertProductionReady())
        ->toThrow(ConfigurationException::class);
});

it('rejects multiple active MFA protection keys before runtime traffic', function (): void {
    $firstEnvironment = 'FOUNDATION_TEST_MFA_KEY_A';
    $secondEnvironment = 'FOUNDATION_TEST_MFA_KEY_B';
    $generator = new KeyMaterialGenerator();
    $_ENV[$firstEnvironment] = $generator->forAead();
    $_ENV[$secondEnvironment] = $generator->forAead();

    try {
        $config = new ConfigRepository([
            'auth' => [
                'otp' => [
                    'secret_protection' => [
                        'keys' => [
                            ['id' => 'a', 'environment' => $firstEnvironment, 'status' => 'active'],
                            ['id' => 'b', 'environment' => $secondEnvironment, 'status' => 'active'],
                        ],
                    ],
                ],
            ],
        ]);

        expect(fn() => (new AuthMfaKeyResolver($config))->mfaSecretKeyRing())
            ->toThrow(ConfigurationException::class);
    } finally {
        unset($_ENV[$firstEnvironment], $_ENV[$secondEnvironment]);
    }
});
