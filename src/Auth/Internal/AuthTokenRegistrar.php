<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Epicrypt\Token\Jwt\SymmetricJwt;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptAccessTokenService;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptEmailVerificationTokenService;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPasswordlessTokenService;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPasswordResetTokenService;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptRefreshTokenService;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptTokenFactory;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Storage\RememberTokenStoreInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthTokenDriver;
use Infocyph\Foundation\Auth\Support\HmacTokenCodec;
use Infocyph\Foundation\Auth\Support\SimpleAccessTokenService;
use Infocyph\Foundation\Auth\Support\SimpleEmailVerificationTokenService;
use Infocyph\Foundation\Auth\Support\SimplePasswordlessTokenService;
use Infocyph\Foundation\Auth\Support\SimplePasswordResetTokenService;
use Infocyph\Foundation\Auth\Support\SimpleRefreshTokenService;
use Infocyph\Foundation\Auth\Support\SimpleRememberTokenService;
use Infocyph\InterMix\DI\ContainerBuilder;

final readonly class AuthTokenRegistrar extends AbstractAuthRegistrar
{
    public function __construct(
        Application $app,
        ContainerBuilder $builder,
        private AuthSecretResolver $secrets,
        private EpicryptTokenPolicyResolver $epicrypt,
    ) {
        parent::__construct($app, $builder);
    }

    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->tokens() === AuthTokenDriver::SECURITY) {
            $this->requirePackage(SymmetricJwt::class, 'infocyph/epicrypt', 'crypto');
            $this->registerEpicryptTokens();

            return;
        }

        $this->recipe(HmacTokenCodec::class, HmacTokenCodec::class, [
            $this->secrets->tokenSecret(),
        ]);
        $this->registerSimpleTokens();
    }

    /** @param class-string<object> $implementation @param class-string<object> $dependency */
    private function bindClockedToken(string $service, string $implementation, string $dependency): void
    {
        $this->recipe($service, $implementation, [
            $this->ref($dependency),
            $this->ref(ClockInterface::class),
        ]);
    }

    private function bindRememberTokens(): void
    {
        $this->recipe(RememberTokenServiceInterface::class, SimpleRememberTokenService::class, [
            $this->ref(RememberTokenStoreInterface::class),
            $this->ref(ClockInterface::class),
            $this->intConfig('auth.remember_me_ttl', 2592000),
        ]);
    }

    /** @param class-string<object> $implementation @param class-string<object> $dependency */
    private function bindSingleDependencyToken(string $service, string $implementation, string $dependency): void
    {
        $this->recipe($service, $implementation, [$this->ref($dependency)]);
    }

    /** @param class-string<object> $implementation @param class-string<object> $dependency */
    private function bindTimedClockedToken(
        string $service,
        string $implementation,
        string $dependency,
        string $ttlKey,
        int $ttlDefault,
    ): void {
        $this->recipe($service, $implementation, [
            $this->ref($dependency),
            $this->ref(ClockInterface::class),
            $this->intConfig($ttlKey, $ttlDefault),
        ]);
    }

    /** @param class-string<object> $implementation @param class-string<object> $dependency */
    private function bindTimedSingleDependencyToken(
        string $service,
        string $implementation,
        string $dependency,
        string $ttlKey,
        int $ttlDefault,
    ): void {
        $this->recipe($service, $implementation, [
            $this->ref($dependency),
            $this->intConfig($ttlKey, $ttlDefault),
        ]);
    }

    private function registerEpicryptTokens(): void
    {
        $this->recipe(EpicryptTokenFactory::class, EpicryptTokenFactory::class, [
            $this->secrets->tokenSecret($this->epicrypt->minimumKeyBytes()),
            $this->ref(ClockInterface::class),
            $this->epicrypt->issuer(),
            $this->epicrypt->audience(),
            $this->epicrypt->algorithm()->value,
            $this->epicrypt->maximumLifetimeSeconds(),
            $this->epicrypt->leewaySeconds(),
        ]);

        $this->bindSingleDependencyToken(AccessTokenServiceInterface::class, EpicryptAccessTokenService::class, EpicryptTokenFactory::class);
        $this->bindSingleDependencyToken(RefreshTokenServiceInterface::class, EpicryptRefreshTokenService::class, EpicryptTokenFactory::class);
        $this->bindTimedSingleDependencyToken(PasswordResetTokenServiceInterface::class, EpicryptPasswordResetTokenService::class, EpicryptTokenFactory::class, 'auth.password_reset_ttl', 3600);
        $this->bindTimedSingleDependencyToken(EmailVerificationTokenServiceInterface::class, EpicryptEmailVerificationTokenService::class, EpicryptTokenFactory::class, 'auth.email_verification_ttl', 3600);
        $this->bindTimedSingleDependencyToken(PasswordlessTokenServiceInterface::class, EpicryptPasswordlessTokenService::class, EpicryptTokenFactory::class, 'auth.passwordless_ttl', 900);
        $this->bindRememberTokens();
    }

    private function registerSimpleTokens(): void
    {
        $this->bindClockedToken(AccessTokenServiceInterface::class, SimpleAccessTokenService::class, HmacTokenCodec::class);
        $this->bindClockedToken(RefreshTokenServiceInterface::class, SimpleRefreshTokenService::class, HmacTokenCodec::class);
        $this->bindTimedClockedToken(PasswordResetTokenServiceInterface::class, SimplePasswordResetTokenService::class, HmacTokenCodec::class, 'auth.password_reset_ttl', 3600);
        $this->bindTimedClockedToken(EmailVerificationTokenServiceInterface::class, SimpleEmailVerificationTokenService::class, HmacTokenCodec::class, 'auth.email_verification_ttl', 3600);
        $this->bindTimedClockedToken(PasswordlessTokenServiceInterface::class, SimplePasswordlessTokenService::class, HmacTokenCodec::class, 'auth.passwordless_ttl', 900);
        $this->bindRememberTokens();
    }
}
