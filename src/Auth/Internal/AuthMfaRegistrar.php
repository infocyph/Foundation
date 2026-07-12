<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpRecoveryCodeService;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpReplayStore;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthMfaDriver;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaVerifierInterface;
use Infocyph\Foundation\Auth\Mfa\RecoveryCodeServiceInterface;
use Infocyph\Foundation\Auth\Support\InMemoryRecoveryCodeService;
use Infocyph\Foundation\Auth\Support\SimpleMfaVerifier;
use Infocyph\OTP\Contracts\ReplayStoreInterface;
use Infocyph\OTP\RecoveryCodes;
use Infocyph\OTP\Stores\InMemoryRecoveryCodeStore;

final readonly class AuthMfaRegistrar extends AbstractAuthRegistrar
{
    public function __construct(
        Application $app,
        \Infocyph\InterMix\DI\Container $container,
        private AuthSecretResolver $secrets,
    ) {
        parent::__construct($app, $container);
    }

    public function register(AuthDriverResolver $drivers): void
    {
        $this->registerOtpSupport();

        $driver = $drivers->mfa();

        if ($driver === AuthMfaDriver::OTP) {
            $this->registerOtpDriver();

            return;
        }

        $this->singleton(MfaVerifierInterface::class, fn() => new SimpleMfaVerifier(
            $this->stringConfig('auth.mfa_default_code', '000000'),
        ));
        $this->singleton(RecoveryCodeServiceInterface::class, fn() => new InMemoryRecoveryCodeService());
    }

    private function registerOtpDriver(): void
    {
        $this->singleton(MfaVerifierInterface::class, fn() => $this->app->make(OtpMfaVerifier::class));
        $this->singleton(RecoveryCodeServiceInterface::class, fn() => $this->app->make(OtpRecoveryCodeService::class));
    }

    private function registerOtpSupport(): void
    {
        $this->singleton(ReplayStoreInterface::class, fn() => new OtpReplayStore(
            $this->app->make(TtlStoreInterface::class),
            max(1, $this->intConfig('auth.otp.replay.ttl', 90)),
        ));

        $this->singleton(OtpProvisioningService::class, fn() => new OtpProvisioningService(
            issuer: $this->stringConfig('auth.otp.issuer', 'Foundation'),
            algorithm: $this->stringConfig('auth.otp.totp.algorithm', 'sha1'),
            digits: $this->intConfig('auth.otp.totp.digits', 6),
            period: $this->intConfig('auth.otp.totp.period', 30),
            secretBytes: $this->intConfig('auth.otp.totp.secret_bytes', 64),
        ));

        $this->singleton(RecoveryCodes::class, fn() => new RecoveryCodes(
            new InMemoryRecoveryCodeStore(),
            hashKey: $this->secrets->tokenSecret(),
        ));

        $this->singleton(OtpMfaVerifier::class, fn() => new OtpMfaVerifier(
            factors: $this->app->make(MfaFactorStoreInterface::class),
            replayStore: $this->boolConfig('auth.otp.replay.enabled', true)
                ? $this->app->make(ReplayStoreInterface::class)
                : null,
            window: $this->intConfig('auth.otp.totp.window', 1),
        ));

        $this->singleton(OtpRecoveryCodeService::class, fn() => new OtpRecoveryCodeService(
            recoveryCodes: $this->app->make(RecoveryCodes::class),
            defaultCount: $this->intConfig('auth.otp.recovery_codes.count', 10),
            codeLength: $this->intConfig('auth.otp.recovery_codes.length', 10),
        ));
    }
}
