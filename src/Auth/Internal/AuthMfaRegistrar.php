<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpRecoveryCodeService;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpRecoveryCodeStore;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthMfaDriver;
use Infocyph\Foundation\Auth\Mfa\MfaFactorCompareAndSwapStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaVerifierInterface;
use Infocyph\Foundation\Auth\Mfa\RecoveryCodeServiceInterface;
use Infocyph\Foundation\Auth\Support\InMemoryRecoveryCodeService;
use Infocyph\Foundation\Auth\Support\SimpleMfaVerifier;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\OTP\Contracts\RecoveryCodeStoreInterface;
use Infocyph\OTP\RecoveryCodes;
use Infocyph\OTP\TOTP;

final readonly class AuthMfaRegistrar extends AbstractAuthRegistrar
{
    public function __construct(
        Application $app,
        ContainerBuilder $builder,
        private AuthSecretResolver $secrets,
    ) {
        parent::__construct($app, $builder);
    }

    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->mfa() === AuthMfaDriver::OTP) {
            $this->requirePackage(TOTP::class, 'infocyph/otp', 'otp');
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
        if (!$this->hasExplicitBinding(OtpProvisioningService::class)) {
            $this->recipe(OtpProvisioningService::class, OtpProvisioningService::class, [
                $this->stringConfig('auth.otp.issuer', 'Foundation'),
                $this->stringConfig('auth.otp.totp.algorithm', 'sha1'),
                $this->intConfig('auth.otp.totp.digits', 6),
                $this->intConfig('auth.otp.totp.period', 30),
                $this->intConfig('auth.otp.totp.secret_bytes', 20),
                $this->intConfig('auth.otp.hotp.look_ahead', 5),
            ]);
        }

        if (!$this->hasExplicitBinding(RecoveryCodeStoreInterface::class)) {
            // CAS capability validation is an intentional runtime adapter boundary.
            $this->singleton(RecoveryCodeStoreInterface::class, function (): RecoveryCodeStoreInterface {
                $factors = $this->service(MfaFactorStoreInterface::class);
                if (!$factors instanceof MfaFactorCompareAndSwapStoreInterface) {
                    throw new \LogicException(
                        'OTP recovery codes require an MFA factor store with atomic compare-and-swap support.',
                    );
                }
                return new OtpRecoveryCodeStore($factors);
            });
        }
        if (!$this->hasExplicitBinding(RecoveryCodes::class)) {
            $this->recipe(RecoveryCodes::class, RecoveryCodes::class, [
                $this->ref(RecoveryCodeStoreInterface::class),
                hash_hmac('sha256', 'foundation:otp-recovery:v1', $this->secrets->tokenSecret(), true),
            ]);
        }

        if (!$this->hasExplicitBinding(OtpMfaVerifier::class)) {
            // Replay-store selection plus CAS validation remain runtime adapter boundaries.
            $this->singleton(OtpMfaVerifier::class, function (): OtpMfaVerifier {
                $factors = $this->service(MfaFactorStoreInterface::class);
                if (!$factors instanceof MfaFactorCompareAndSwapStoreInterface) {
                    throw new \LogicException(
                        'OTP MFA requires an MFA factor store with atomic compare-and-swap support.',
                    );
                }

                return new OtpMfaVerifier(
                    factors: $factors,
                    stateCache: $this->otpReplayStore(),
                    window: $this->intConfig('auth.otp.totp.window', 1),
                    ocraReplayTtl: $this->intConfig('auth.otp.replay.ttl', 90),
                );
            });
        }

        if (!$this->hasExplicitBinding(OtpRecoveryCodeService::class)) {
            $this->recipe(OtpRecoveryCodeService::class, OtpRecoveryCodeService::class, [
                $this->ref(RecoveryCodes::class),
                $this->intConfig('auth.otp.recovery_codes.count', 10),
                $this->intConfig('auth.otp.recovery_codes.length', 12),
            ]);
        }
    }

    private function otpReplayStore(): AuthenticationStateCacheInterface
    {
        $configured = $this->app->config()->get('auth.otp.replay.store');
        $storeName = is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
        $store = $this->service(CacheLayerFactory::class)->make($storeName);
        if (!$store instanceof AuthenticationStateCacheInterface) {
            throw new \LogicException(
                'OTP replay protection requires a CacheLayer AuthenticationStateCacheInterface store.',
            );
        }

        return $store;
    }

    private function registerOtpDriver(): void
    {
        if (!$this->hasExplicitBinding(MfaVerifierInterface::class)) {
            $this->alias(MfaVerifierInterface::class, OtpMfaVerifier::class);
        }
        if (!$this->hasExplicitBinding(RecoveryCodeServiceInterface::class)) {
            $this->alias(RecoveryCodeServiceInterface::class, OtpRecoveryCodeService::class);
        }
    }
}
