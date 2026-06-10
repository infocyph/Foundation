<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\AuthLayer\Contract\Cache\TtlStoreInterface;
use Infocyph\AuthLayer\Mfa\MfaFactorStoreInterface;
use Infocyph\AuthLayer\Mfa\MfaVerifierInterface;
use Infocyph\AuthLayer\Mfa\RecoveryCodeServiceInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthMfaDriver;
use Infocyph\Foundation\Auth\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Otp\OtpRecoveryCodeService;
use Infocyph\Foundation\Auth\Otp\OtpReplayStore;
use Infocyph\Foundation\Auth\Support\InMemoryRecoveryCodeService;
use Infocyph\Foundation\Auth\Support\SimpleMfaVerifier;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\OTP\Contracts\ReplayStoreInterface;
use Infocyph\OTP\RecoveryCodes;
use Infocyph\OTP\Stores\InMemoryRecoveryCodeStore;

final readonly class AuthMfaRegistrar
{
    public function __construct(
        private Application $app,
        private Container $container,
        private AuthSecretResolver $secrets,
    ) {}

    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->mfa() === AuthMfaDriver::OTP) {
            $this->registerOtp();

            return;
        }

        if ($drivers->mfa() !== AuthMfaDriver::SIMPLE) {
            throw new ConfigurationException(sprintf(
                'Auth MFA driver "%s" is not implemented yet.',
                $drivers->mfa()->value,
            ));
        }

        $this->container->bind(MfaVerifierInterface::class, fn() => new SimpleMfaVerifier(
            (string) $this->app->config()->get('auth.mfa_default_code', '000000'),
        ), LifetimeEnum::Singleton);
        $this->container->bind(RecoveryCodeServiceInterface::class, fn() => new InMemoryRecoveryCodeService(), LifetimeEnum::Singleton);
    }

    private function registerOtp(): void
    {
        $app = $this->app;
        $container = $this->container;

        $container->bind(ReplayStoreInterface::class, fn() => new OtpReplayStore(
            $container->get(TtlStoreInterface::class),
            max(1, (int) $app->config()->get('auth.otp.replay.ttl', 90)),
        ), LifetimeEnum::Singleton);

        $container->bind(OtpProvisioningService::class, fn() => new OtpProvisioningService(
            issuer: (string) $app->config()->get('auth.otp.issuer', 'Foundation'),
            algorithm: (string) $app->config()->get('auth.otp.totp.algorithm', 'sha1'),
            digits: (int) $app->config()->get('auth.otp.totp.digits', 6),
            period: (int) $app->config()->get('auth.otp.totp.period', 30),
            secretBytes: (int) $app->config()->get('auth.otp.totp.secret_bytes', 64),
        ), LifetimeEnum::Singleton);

        $container->bind(RecoveryCodes::class, fn() => new RecoveryCodes(
            new InMemoryRecoveryCodeStore(),
            hashKey: $this->secrets->tokenSecret(),
        ), LifetimeEnum::Singleton);

        $container->bind(MfaVerifierInterface::class, fn() => new OtpMfaVerifier(
            factors: $container->get(MfaFactorStoreInterface::class),
            replayStore: (bool) $app->config()->get('auth.otp.replay.enabled', true)
                ? $container->get(ReplayStoreInterface::class)
                : null,
            window: (int) $app->config()->get('auth.otp.totp.window', 1),
        ), LifetimeEnum::Singleton);

        $container->bind(RecoveryCodeServiceInterface::class, fn() => new OtpRecoveryCodeService(
            recoveryCodes: $container->get(RecoveryCodes::class),
            defaultCount: (int) $app->config()->get('auth.otp.recovery_codes.count', 10),
            codeLength: (int) $app->config()->get('auth.otp.recovery_codes.length', 10),
        ), LifetimeEnum::Singleton);
    }
}
