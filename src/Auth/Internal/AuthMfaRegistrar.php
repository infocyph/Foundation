<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Epicrypt\Generate\KeyMaterial\KeyDeriver;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpChallengeFactorService;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpRecoveryCodeService;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthMfaDriver;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaVerifierInterface;
use Infocyph\Foundation\Auth\Mfa\RecoveryCodeServiceInterface;
use Infocyph\Foundation\Auth\Support\InMemoryRecoveryCodeService;
use Infocyph\Foundation\Auth\Support\SimpleMfaVerifier;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\OTP\Contracts\RecoveryCodeStoreInterface;
use Infocyph\OTP\RecoveryCodes;
use Infocyph\OTP\TOTP;

final readonly class AuthMfaRegistrar extends AbstractAuthRegistrar
{
    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->mfa() === AuthMfaDriver::OTP) {
            $this->requirePackage(TOTP::class, 'infocyph/otp', 'otp');
            $this->requirePackage(KeyDeriver::class, 'infocyph/epicrypt', 'crypto');
            $this->registerOtpSupport();
            $this->registerOtpDriver();

            return;
        }

        $this->recipe(MfaVerifierInterface::class, SimpleMfaVerifier::class, [
            $this->stringConfig('auth.mfa_default_code', '000000'),
        ]);
        $this->recipe(RecoveryCodeServiceInterface::class, InMemoryRecoveryCodeService::class);
    }

    public function registerOtpSupport(): void
    {
        $this->recipe(OtpProvisioningService::class, OtpProvisioningService::class, [
            $this->stringConfig('auth.otp.issuer', 'Foundation'),
            $this->stringConfig('auth.otp.totp.algorithm', 'sha1'),
            $this->intConfig('auth.otp.totp.digits', 6),
            $this->intConfig('auth.otp.totp.period', 30),
            $this->intConfig('auth.otp.totp.secret_bytes', 20),
            $this->intConfig('auth.otp.hotp.look_ahead', 5),
        ]);

        $this->staticRecipe(
            RecoveryCodeStoreInterface::class,
            AuthMfaGraphFactory::class,
            'recoveryCodeStore',
            [$this->ref(MfaFactorStoreInterface::class)],
        );
        $this->staticRecipe(
            RecoveryCodes::class,
            AuthMfaGraphFactory::class,
            'recoveryCodes',
            [
                $this->ref(RecoveryCodeStoreInterface::class),
                $this->ref(ConfigRepository::class),
            ],
        );

        $configured = $this->config->get('auth.otp.replay.store');
        $storeName = is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
        $this->staticRecipe(
            OtpChallengeFactorService::class,
            AuthMfaGraphFactory::class,
            'challengeFactors',
            [
                $this->ref(CacheManager::class),
                $storeName,
            ],
        );

        $this->staticRecipe(
            OtpMfaVerifier::class,
            AuthMfaGraphFactory::class,
            'verifier',
            [
                $this->ref(MfaFactorStoreInterface::class),
                $this->ref(OtpChallengeFactorService::class),
                $this->intConfig('auth.otp.totp.window', 1),
                $this->intConfig('auth.otp.replay.ttl', 90),
            ],
        );

        $this->recipe(OtpRecoveryCodeService::class, OtpRecoveryCodeService::class, [
            $this->ref(RecoveryCodes::class),
            $this->intConfig('auth.otp.recovery_codes.count', 10),
            $this->intConfig('auth.otp.recovery_codes.length', 12),
        ]);
    }

    private function registerOtpDriver(): void
    {
        $this->alias(MfaVerifierInterface::class, OtpMfaVerifier::class);
        $this->alias(RecoveryCodeServiceInterface::class, OtpRecoveryCodeService::class);
    }
}
