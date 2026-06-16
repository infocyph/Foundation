<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Account\AccountManager;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutConfig;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager;
use Infocyph\Foundation\Auth\Authentication\PasswordChange\PasswordChangeManager;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessManager;
use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberMeManager;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\Session\SessionConfig;
use Infocyph\Foundation\Auth\Authentication\Session\SessionManager;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\TokenAuthManager;
use Infocyph\Foundation\Auth\Contract\Cache\CounterStoreInterface;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\EmailVerificationStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\LockoutStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\PasswordResetStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\RefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\RememberTokenStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;
use Infocyph\Foundation\Auth\Device\DeviceManager;
use Infocyph\Foundation\Auth\Device\DeviceStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\Mfa\MfaVerifierInterface;
use Infocyph\Foundation\Auth\Mfa\RecoveryCodeServiceInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyManager;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthManagerRegistrar
{
    public function __construct(
        private Application $app,
        private Container $container,
    ) {}

    public function register(): void
    {
        $app = $this->app;
        $container = $this->container;

        $container->bind(SessionManager::class, fn() => new SessionManager(
            sessions: $container->get(SessionStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            config: new SessionConfig(
                absoluteTtlSeconds: (int) $app->config()->get('auth.session_ttl', 3600),
                recentAuthWindowSeconds: (int) $app->config()->get('auth.recent_auth_window', 900),
            ),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(LockoutManager::class, fn() => new LockoutManager(
            counters: $container->get(CounterStoreInterface::class),
            locks: $container->get(LockoutStoreInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            config: new LockoutConfig(
                maxLoginFailures: (int) $app->config()->get('auth.lockout.max_login_failures', 5),
                maxMfaFailures: (int) $app->config()->get('auth.lockout.max_mfa_failures', 5),
                maxPasskeyFailures: (int) $app->config()->get('auth.lockout.max_passkey_failures', 5),
                windowSeconds: (int) $app->config()->get('auth.lockout.window_seconds', 900),
                lockSeconds: (int) $app->config()->get('auth.lockout.lock_seconds', 900),
            ),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(AccountManager::class, fn() => new AccountManager(
            accounts: $container->get(AccountProviderInterface::class),
            store: $container->get(AccountStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(PasswordChangeManager::class, fn() => new PasswordChangeManager(
            accounts: $container->get(AccountProviderInterface::class),
            accountStore: $container->get(AccountStoreInterface::class),
            passwords: $container->get(PasswordVerifierInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            notifier: $container->get(AuthNotifierInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(PasswordResetManager::class, fn() => new PasswordResetManager(
            tokens: $container->get(PasswordResetTokenServiceInterface::class),
            store: $container->get(PasswordResetStoreInterface::class),
            accounts: $container->get(AccountStoreInterface::class),
            notifier: $container->get(AuthNotifierInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            ttlSeconds: (int) $app->config()->get('auth.password_reset_ttl', 3600),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(EmailVerificationManager::class, fn() => new EmailVerificationManager(
            tokens: $container->get(EmailVerificationTokenServiceInterface::class),
            store: $container->get(EmailVerificationStoreInterface::class),
            accounts: $container->get(AccountStoreInterface::class),
            notifier: $container->get(AuthNotifierInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            ttlSeconds: (int) $app->config()->get('auth.email_verification_ttl', 3600),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(PasswordlessManager::class, fn() => new PasswordlessManager(
            tokens: $container->get(PasswordlessTokenServiceInterface::class),
            notifier: $container->get(AuthNotifierInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(RememberMeManager::class, fn() => new RememberMeManager(
            tokens: $container->get(RememberTokenServiceInterface::class),
            store: $container->get(RememberTokenStoreInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(TokenAuthManager::class, fn() => new TokenAuthManager(
            accessTokens: $container->get(AccessTokenServiceInterface::class),
            refreshTokenService: $container->get(RefreshTokenServiceInterface::class),
            refreshTokens: $container->get(RefreshTokenStoreInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            refreshTtlSeconds: (int) $app->config()->get('auth.refresh_token_ttl', 1209600),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(MfaManager::class, fn() => new MfaManager(
            factors: $container->get(MfaFactorStoreInterface::class),
            verifier: $container->get(MfaVerifierInterface::class),
            recoveryCodes: $container->get(RecoveryCodeServiceInterface::class),
            ttl: $container->get(TtlStoreInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            notifier: $container->get(AuthNotifierInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            challengeTtlSeconds: (int) $app->config()->get('auth.mfa_challenge_ttl', 300),
            satisfiedTtlSeconds: (int) $app->config()->get('auth.mfa_satisfied_ttl', 900),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(PasskeyManager::class, fn() => new PasskeyManager(
            service: $container->get(PasskeyServiceInterface::class),
            credentials: $container->get(PasskeyCredentialStoreInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            notifier: $container->get(AuthNotifierInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            lockouts: $container->get(LockoutManager::class),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(DeviceManager::class, fn() => new DeviceManager(
            devices: $container->get(DeviceStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);
    }
}
