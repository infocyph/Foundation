<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Account\AccountManager;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\{EmailVerificationManager, EmailVerificationTokenServiceInterface};
use Infocyph\Foundation\Auth\Authentication\Impersonation\ImpersonationManager;
use Infocyph\Foundation\Auth\Authentication\Lockout\{LockoutConfig, LockoutManager};
use Infocyph\Foundation\Auth\Authentication\PasswordChange\PasswordChangeManager;
use Infocyph\Foundation\Auth\Authentication\Passwordless\{PasswordlessManager, PasswordlessTokenServiceInterface};
use Infocyph\Foundation\Auth\Authentication\PasswordReset\{PasswordResetManager, PasswordResetTokenServiceInterface};
use Infocyph\Foundation\Auth\Authentication\RememberMe\{RememberMeManager, RememberTokenServiceInterface};
use Infocyph\Foundation\Auth\Authentication\Session\{SessionConfig, SessionManager};
use Infocyph\Foundation\Auth\Authentication\StepUp\StepUpManager;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\{RefreshTokenServiceInterface, TokenAuthManager};
use Infocyph\Foundation\Auth\Contract\Cache\{CounterStoreInterface, TtlStoreInterface};
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\{
    AccountProviderInterface,
    AccountStoreInterface,
    AuditEventStoreInterface,
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
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthManagerRegistrar extends AbstractAuthRegistrar
{
    public function register(): void
    {
        $this->recipe(SessionConfig::class, SessionConfig::class, [
            $this->intConfig('auth.session_ttl', 3600),
            $this->intConfig('auth.recent_auth_window', 900),
        ]);
        $this->recipe(LockoutConfig::class, LockoutConfig::class, [
            $this->intConfig('auth.lockout.max_login_failures', 5),
            $this->intConfig('auth.lockout.max_mfa_failures', 5),
            $this->intConfig('auth.lockout.max_passkey_failures', 5),
            $this->intConfig('auth.lockout.window_seconds', 900),
            $this->intConfig('auth.lockout.lock_seconds', 900),
        ]);
        $this->recipe(SessionManager::class, SessionManager::class, [
            $this->ref(SessionStoreInterface::class), $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(SessionConfig::class), $this->ref(ClockInterface::class),
        ]);
        $this->recipe(LockoutManager::class, LockoutManager::class, [
            $this->ref(CounterStoreInterface::class), $this->ref(LockoutStoreInterface::class),
            $this->ref(AuditEventStoreInterface::class), $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(LockoutConfig::class), $this->ref(ClockInterface::class),
        ]);
        $this->recipe(AccountManager::class, AccountManager::class, [
            $this->ref(AccountProviderInterface::class), $this->ref(AccountStoreInterface::class),
            $this->ref(AuthIdGeneratorInterface::class), $this->ref(ClockInterface::class),
        ]);
        $this->recipe(PasswordChangeManager::class, PasswordChangeManager::class, [
            $this->ref(AccountProviderInterface::class), $this->ref(AccountStoreInterface::class),
            $this->ref(PasswordVerifierInterface::class), $this->ref(AuditEventStoreInterface::class),
            $this->ref(AuthNotifierInterface::class), $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(ClockInterface::class),
        ], LifetimeEnum::Scoped);
        $this->recipe(PasswordResetManager::class, PasswordResetManager::class, [
            $this->ref(PasswordResetTokenServiceInterface::class), $this->ref(PasswordResetStoreInterface::class),
            $this->ref(AccountStoreInterface::class), $this->ref(AuthNotifierInterface::class),
            $this->ref(AuditEventStoreInterface::class), $this->ref(AuthIdGeneratorInterface::class),
            $this->intConfig('auth.password_reset_ttl', 3600), $this->ref(ClockInterface::class),
        ], LifetimeEnum::Scoped);
        $this->recipe(EmailVerificationManager::class, EmailVerificationManager::class, [
            $this->ref(EmailVerificationTokenServiceInterface::class), $this->ref(EmailVerificationStoreInterface::class),
            $this->ref(AccountStoreInterface::class), $this->ref(AuthNotifierInterface::class),
            $this->ref(AuditEventStoreInterface::class), $this->ref(AuthIdGeneratorInterface::class),
            $this->intConfig('auth.email_verification_ttl', 3600), $this->ref(ClockInterface::class),
        ], LifetimeEnum::Scoped);
        $this->recipe(PasswordlessManager::class, PasswordlessManager::class, [
            $this->ref(PasswordlessTokenServiceInterface::class), $this->ref(AuthNotifierInterface::class),
        ], LifetimeEnum::Scoped);
        $this->recipe(RememberMeManager::class, RememberMeManager::class, [
            $this->ref(RememberTokenServiceInterface::class), $this->ref(RememberTokenStoreInterface::class),
            $this->ref(AuditEventStoreInterface::class), $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(TokenAuthManager::class, TokenAuthManager::class, [
            $this->ref(AccessTokenServiceInterface::class), $this->ref(RefreshTokenServiceInterface::class),
            $this->ref(RefreshTokenStoreInterface::class), $this->ref(AuditEventStoreInterface::class),
            $this->ref(AuthIdGeneratorInterface::class), $this->intConfig('auth.refresh_token_ttl', 1209600),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(MfaManager::class, MfaManager::class, [
            $this->ref(MfaFactorStoreInterface::class), $this->ref(MfaVerifierInterface::class),
            $this->ref(RecoveryCodeServiceInterface::class), $this->ref(TtlStoreInterface::class),
            $this->ref(AuditEventStoreInterface::class), $this->ref(AuthNotifierInterface::class),
            $this->ref(AuthIdGeneratorInterface::class), $this->intConfig('auth.mfa_challenge_ttl', 300),
            $this->intConfig('auth.mfa_satisfied_ttl', 900), $this->ref(ClockInterface::class),
        ], LifetimeEnum::Scoped);
        $this->recipe(OtpManager::class, OtpManager::class, [
            $this->ref(MfaManager::class), $this->ref(MfaFactorStoreInterface::class),
            $this->ref(OtpProvisioningService::class), $this->ref(OtpMfaVerifier::class),
        ], LifetimeEnum::Scoped);
        $this->recipe(PasskeyManager::class, PasskeyManager::class, [
            $this->ref(PasskeyServiceInterface::class), $this->ref(PasskeyCredentialStoreInterface::class),
            $this->ref(AuditEventStoreInterface::class), $this->ref(AuthNotifierInterface::class),
            $this->ref(AuthIdGeneratorInterface::class),
            $this->hasExplicitBinding(LockoutManager::class) ? $this->ref(LockoutManager::class) : null,
            $this->ref(ClockInterface::class),
        ], LifetimeEnum::Scoped);
        $this->recipe(DeviceManager::class, DeviceManager::class, [
            $this->ref(DeviceStoreInterface::class), $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(ImpersonationManager::class, ImpersonationManager::class, [
            $this->ref(AuditEventStoreInterface::class), $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(StepUpManager::class, StepUpManager::class, [
            $this->ref(TtlStoreInterface::class), $this->ref(ClockInterface::class),
        ]);
    }
}
