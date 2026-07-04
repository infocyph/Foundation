<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

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

final readonly class AuthTokenRegistrar extends AbstractAuthRegistrar
{
    public function __construct(
        Application $app,
        \Infocyph\InterMix\DI\Container $container,
        private AuthSecretResolver $secrets,
        private EpicryptConfigResolver $epicrypt,
    ) {
        parent::__construct($app, $container);
    }

    public function register(AuthDriverResolver $drivers): void
    {
        $driver = $drivers->tokens();

        $this->singleton(HmacTokenCodec::class, fn() => new HmacTokenCodec(
            $this->secrets->tokenSecret(),
        ));

        if ($driver === AuthTokenDriver::EPICRYPT) {
            $this->registerEpicryptTokens();

            return;
        }

        $this->registerSimpleTokens();
    }

    /**
     * @param class-string<object> $implementation
     * @param class-string<object> $dependency
     */
    private function bindClockedToken(string $service, string $implementation, string $dependency): void
    {
        $this->singleton($service, fn() => new $implementation(
            $this->service($dependency),
            $this->clock(),
        ));
    }

    private function bindRememberTokens(): void
    {
        $this->singleton(RememberTokenServiceInterface::class, fn() => new SimpleRememberTokenService(
            $this->service(RememberTokenStoreInterface::class),
            $this->clock(),
            $this->intConfig('auth.remember_me_ttl', 2592000),
        ));
    }

    /**
     * @param class-string<object> $implementation
     * @param class-string<object> $dependency
     */
    private function bindSingleDependencyToken(string $service, string $implementation, string $dependency): void
    {
        $this->singleton($service, fn() => new $implementation(
            $this->service($dependency),
        ));
    }

    /**
     * @param class-string<object> $implementation
     * @param class-string<object> $dependency
     */
    private function bindTimedClockedToken(
        string $service,
        string $implementation,
        string $dependency,
        string $ttlKey,
        int $ttlDefault,
    ): void {
        $this->singleton($service, fn() => new $implementation(
            $this->service($dependency),
            $this->clock(),
            $this->intConfig($ttlKey, $ttlDefault),
        ));
    }

    /**
     * @param class-string<object> $implementation
     * @param class-string<object> $dependency
     */
    private function bindTimedSingleDependencyToken(
        string $service,
        string $implementation,
        string $dependency,
        string $ttlKey,
        int $ttlDefault,
    ): void {
        $this->singleton($service, fn() => new $implementation(
            $this->service($dependency),
            $this->intConfig($ttlKey, $ttlDefault),
        ));
    }

    private function registerEpicryptTokens(): void
    {
        $this->singleton(EpicryptTokenFactory::class, fn() => new EpicryptTokenFactory(
            key: $this->secrets->tokenSecret(),
            clock: $this->clock(),
            issuer: $this->epicrypt->tokenIssuer(),
            audience: $this->epicrypt->tokenAudience(),
            leewaySeconds: $this->epicrypt->tokenLeeway(),
        ));

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
