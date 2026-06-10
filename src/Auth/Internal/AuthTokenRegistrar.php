<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\AuthLayer\Authentication\EmailVerification\EmailVerificationTokenServiceInterface;
use Infocyph\AuthLayer\Authentication\PasswordReset\PasswordResetTokenServiceInterface;
use Infocyph\AuthLayer\Authentication\Passwordless\PasswordlessTokenServiceInterface;
use Infocyph\AuthLayer\Authentication\RememberMe\RememberTokenServiceInterface;
use Infocyph\AuthLayer\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Contract\Security\AccessTokenServiceInterface;
use Infocyph\AuthLayer\Contract\Storage\RememberTokenStoreInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthTokenDriver;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptAccessTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptEmailVerificationTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptPasswordResetTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptPasswordlessTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptRefreshTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptTokenFactory;
use Infocyph\Foundation\Auth\Support\HmacTokenCodec;
use Infocyph\Foundation\Auth\Support\SimpleAccessTokenService;
use Infocyph\Foundation\Auth\Support\SimpleEmailVerificationTokenService;
use Infocyph\Foundation\Auth\Support\SimplePasswordResetTokenService;
use Infocyph\Foundation\Auth\Support\SimplePasswordlessTokenService;
use Infocyph\Foundation\Auth\Support\SimpleRefreshTokenService;
use Infocyph\Foundation\Auth\Support\SimpleRememberTokenService;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthTokenRegistrar
{
    public function __construct(
        private Application $app,
        private Container $container,
        private AuthSecretResolver $secrets,
        private EpicryptConfigResolver $epicrypt,
    ) {}

    public function register(AuthDriverResolver $drivers): void
    {
        $this->container->bind(HmacTokenCodec::class, fn() => new HmacTokenCodec(
            $this->secrets->tokenSecret(),
        ), LifetimeEnum::Singleton);

        if ($drivers->tokens() === AuthTokenDriver::EPICRYPT) {
            $this->registerEpicryptTokens();

            return;
        }

        if ($drivers->tokens() !== AuthTokenDriver::SIMPLE) {
            throw new ConfigurationException(sprintf(
                'Auth token driver "%s" is not implemented yet.',
                $drivers->tokens()->value,
            ));
        }

        $this->registerSimpleTokens();
    }

    private function registerEpicryptTokens(): void
    {
        $app = $this->app;
        $container = $this->container;

        $container->bind(EpicryptTokenFactory::class, fn() => new EpicryptTokenFactory(
            key: $this->secrets->tokenSecret(),
            clock: $container->get(ClockInterface::class),
            issuer: $this->epicrypt->tokenIssuer(),
            audience: $this->epicrypt->tokenAudience(),
            leewaySeconds: $this->epicrypt->tokenLeeway(),
        ), LifetimeEnum::Singleton);

        $container->bind(AccessTokenServiceInterface::class, fn() => new EpicryptAccessTokenService(
            $container->get(EpicryptTokenFactory::class),
        ), LifetimeEnum::Singleton);

        $container->bind(RefreshTokenServiceInterface::class, fn() => new EpicryptRefreshTokenService(
            $container->get(EpicryptTokenFactory::class),
        ), LifetimeEnum::Singleton);

        $container->bind(PasswordResetTokenServiceInterface::class, fn() => new EpicryptPasswordResetTokenService(
            $container->get(EpicryptTokenFactory::class),
            (int) $app->config()->get('auth.password_reset_ttl', 3600),
        ), LifetimeEnum::Singleton);

        $container->bind(EmailVerificationTokenServiceInterface::class, fn() => new EpicryptEmailVerificationTokenService(
            $container->get(EpicryptTokenFactory::class),
            (int) $app->config()->get('auth.email_verification_ttl', 3600),
        ), LifetimeEnum::Singleton);

        $container->bind(PasswordlessTokenServiceInterface::class, fn() => new EpicryptPasswordlessTokenService(
            $container->get(EpicryptTokenFactory::class),
            (int) $app->config()->get('auth.passwordless_ttl', 900),
        ), LifetimeEnum::Singleton);

        $container->bind(RememberTokenServiceInterface::class, fn() => new SimpleRememberTokenService(
            $container->get(RememberTokenStoreInterface::class),
            $container->get(ClockInterface::class),
            (int) $app->config()->get('auth.remember_me_ttl', 2592000),
        ), LifetimeEnum::Singleton);
    }

    private function registerSimpleTokens(): void
    {
        $app = $this->app;
        $container = $this->container;

        $container->bind(AccessTokenServiceInterface::class, fn() => new SimpleAccessTokenService(
            $container->get(HmacTokenCodec::class),
            $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(RefreshTokenServiceInterface::class, fn() => new SimpleRefreshTokenService(
            $container->get(HmacTokenCodec::class),
            $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(PasswordResetTokenServiceInterface::class, fn() => new SimplePasswordResetTokenService(
            $container->get(HmacTokenCodec::class),
            $container->get(ClockInterface::class),
            (int) $app->config()->get('auth.password_reset_ttl', 3600),
        ), LifetimeEnum::Singleton);

        $container->bind(EmailVerificationTokenServiceInterface::class, fn() => new SimpleEmailVerificationTokenService(
            $container->get(HmacTokenCodec::class),
            $container->get(ClockInterface::class),
            (int) $app->config()->get('auth.email_verification_ttl', 3600),
        ), LifetimeEnum::Singleton);

        $container->bind(PasswordlessTokenServiceInterface::class, fn() => new SimplePasswordlessTokenService(
            $container->get(HmacTokenCodec::class),
            $container->get(ClockInterface::class),
            (int) $app->config()->get('auth.passwordless_ttl', 900),
        ), LifetimeEnum::Singleton);

        $container->bind(RememberTokenServiceInterface::class, fn() => new SimpleRememberTokenService(
            $container->get(RememberTokenStoreInterface::class),
            $container->get(ClockInterface::class),
            (int) $app->config()->get('auth.remember_me_ttl', 2592000),
        ), LifetimeEnum::Singleton);
    }
}
