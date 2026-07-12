<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Account\AccountManager;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\{EmailVerificationManager, EmailVerificationTokenServiceInterface};
use Infocyph\Foundation\Auth\Authentication\Lockout\{LockoutConfig, LockoutManager};
use Infocyph\Foundation\Auth\Authentication\PasswordChange\PasswordChangeManager;
use Infocyph\Foundation\Auth\Authentication\Passwordless\{PasswordlessManager, PasswordlessTokenServiceInterface};
use Infocyph\Foundation\Auth\Authentication\PasswordReset\{PasswordResetManager, PasswordResetTokenServiceInterface};
use Infocyph\Foundation\Auth\Authentication\RememberMe\{RememberMeManager, RememberTokenServiceInterface};
use Infocyph\Foundation\Auth\Authentication\Session\{SessionConfig, SessionManager};
use Infocyph\Foundation\Auth\Authentication\TokenAuth\{RefreshTokenServiceInterface, TokenAuthManager};
use Infocyph\Foundation\Auth\Contract\Cache\{CounterStoreInterface, TtlStoreInterface};
use Infocyph\Foundation\Auth\Contract\Security\{AccessTokenServiceInterface};
use Infocyph\Foundation\Auth\Contract\Storage\{
    EmailVerificationStoreInterface,
    LockoutStoreInterface,
    PasswordResetStoreInterface,
    RefreshTokenStoreInterface,
    RememberTokenStoreInterface,
    SessionStoreInterface
};
use Infocyph\Foundation\Auth\Device\{DeviceManager, DeviceStoreInterface};
use Infocyph\Foundation\Auth\Mfa\{MfaFactorStoreInterface, MfaManager, MfaVerifierInterface, RecoveryCodeServiceInterface};
use Infocyph\Foundation\Auth\Otp\OtpManager;
use Infocyph\Foundation\Auth\Passkey\{PasskeyCredentialStoreInterface, PasskeyManager, PasskeyServiceInterface};

final readonly class AuthManagerRegistrar extends AbstractAuthRegistrar
{
    public function register(): void
    {
        $this->singleton(SessionManager::class, fn() => new SessionManager(
            sessions: $this->service(SessionStoreInterface::class),
            ids: $this->idGenerator(),
            config: new SessionConfig(
                absoluteTtlSeconds: $this->intConfig('auth.session_ttl', 3600),
                recentAuthWindowSeconds: $this->intConfig('auth.recent_auth_window', 900),
            ),
            clock: $this->clock(),
        ));

        $this->singleton(LockoutManager::class, fn() => new LockoutManager(
            counters: $this->service(CounterStoreInterface::class),
            locks: $this->service(LockoutStoreInterface::class),
            audit: $this->auditStore(),
            ids: $this->idGenerator(),
            config: new LockoutConfig(
                maxLoginFailures: $this->intConfig('auth.lockout.max_login_failures', 5),
                maxMfaFailures: $this->intConfig('auth.lockout.max_mfa_failures', 5),
                maxPasskeyFailures: $this->intConfig('auth.lockout.max_passkey_failures', 5),
                windowSeconds: $this->intConfig('auth.lockout.window_seconds', 900),
                lockSeconds: $this->intConfig('auth.lockout.lock_seconds', 900),
            ),
            clock: $this->clock(),
        ));

        $this->singleton(AccountManager::class, fn() => new AccountManager(
            accounts: $this->accountProvider(),
            store: $this->accountStore(),
            ids: $this->idGenerator(),
            clock: $this->clock(),
        ));

        $this->singleton(PasswordChangeManager::class, fn() => new PasswordChangeManager(
            accounts: $this->accountProvider(),
            accountStore: $this->accountStore(),
            passwords: $this->passwordVerifier(),
            audit: $this->auditStore(),
            notifier: $this->notifier(),
            ids: $this->idGenerator(),
            clock: $this->clock(),
        ));

        $this->singleton(PasswordResetManager::class, fn() => new PasswordResetManager(
            tokens: $this->service(PasswordResetTokenServiceInterface::class),
            store: $this->service(PasswordResetStoreInterface::class),
            accounts: $this->accountStore(),
            notifier: $this->notifier(),
            audit: $this->auditStore(),
            ids: $this->idGenerator(),
            ttlSeconds: $this->intConfig('auth.password_reset_ttl', 3600),
            clock: $this->clock(),
        ));

        $this->singleton(EmailVerificationManager::class, fn() => new EmailVerificationManager(
            tokens: $this->service(EmailVerificationTokenServiceInterface::class),
            store: $this->service(EmailVerificationStoreInterface::class),
            accounts: $this->accountStore(),
            notifier: $this->notifier(),
            audit: $this->auditStore(),
            ids: $this->idGenerator(),
            ttlSeconds: $this->intConfig('auth.email_verification_ttl', 3600),
            clock: $this->clock(),
        ));

        $this->singleton(PasswordlessManager::class, fn() => new PasswordlessManager(
            tokens: $this->service(PasswordlessTokenServiceInterface::class),
            notifier: $this->notifier(),
        ));

        $this->singleton(RememberMeManager::class, fn() => new RememberMeManager(
            tokens: $this->service(RememberTokenServiceInterface::class),
            store: $this->service(RememberTokenStoreInterface::class),
            audit: $this->auditStore(),
            ids: $this->idGenerator(),
            clock: $this->clock(),
        ));

        $this->singleton(TokenAuthManager::class, fn() => new TokenAuthManager(
            accessTokens: $this->service(AccessTokenServiceInterface::class),
            refreshTokenService: $this->service(RefreshTokenServiceInterface::class),
            refreshTokens: $this->service(RefreshTokenStoreInterface::class),
            audit: $this->auditStore(),
            ids: $this->idGenerator(),
            refreshTtlSeconds: $this->intConfig('auth.refresh_token_ttl', 1209600),
            clock: $this->clock(),
        ));

        $this->singleton(MfaManager::class, fn() => new MfaManager(
            factors: $this->service(MfaFactorStoreInterface::class),
            verifier: $this->service(MfaVerifierInterface::class),
            recoveryCodes: $this->service(RecoveryCodeServiceInterface::class),
            ttl: $this->service(TtlStoreInterface::class),
            audit: $this->auditStore(),
            notifier: $this->notifier(),
            ids: $this->idGenerator(),
            challengeTtlSeconds: $this->intConfig('auth.mfa_challenge_ttl', 300),
            satisfiedTtlSeconds: $this->intConfig('auth.mfa_satisfied_ttl', 900),
            clock: $this->clock(),
        ));

        $this->singleton(OtpManager::class, fn() => new OtpManager(
            config: $this->app->config(),
            mfa: $this->service(MfaManager::class),
            factors: $this->service(MfaFactorStoreInterface::class),
            provisioning: $this->service(OtpProvisioningService::class),
        ));

        $this->singleton(PasskeyManager::class, fn() => new PasskeyManager(
            service: $this->service(PasskeyServiceInterface::class),
            credentials: $this->service(PasskeyCredentialStoreInterface::class),
            audit: $this->auditStore(),
            notifier: $this->notifier(),
            ids: $this->idGenerator(),
            lockouts: $this->container->has(LockoutManager::class)
                ? $this->service(LockoutManager::class)
                : null,
            clock: $this->clock(),
        ));

        $this->singleton(DeviceManager::class, fn() => new DeviceManager(
            devices: $this->service(DeviceStoreInterface::class),
            ids: $this->idGenerator(),
            clock: $this->clock(),
        ));
    }
}
