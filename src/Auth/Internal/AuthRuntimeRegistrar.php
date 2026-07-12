<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Account\AccountManager;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\PasswordChange\PasswordChangeManager;
use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessManager;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberMeManager;
use Infocyph\Foundation\Auth\Authentication\Session\SessionManager;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\TokenAuthManager;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\Authorization\Gate\PermissionAuthorizer;
use Infocyph\Foundation\Auth\Authorization\Grant\DelegationManager;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionManager;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Device\DeviceManager;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\Otp\OtpManager;
use Infocyph\Foundation\Auth\Passkey\PasskeyManager;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;

final readonly class AuthRuntimeRegistrar extends AbstractAuthRegistrar
{
    public function register(): void
    {
        $this->singleton(CurrentPrincipalContext::class, fn() => new CurrentPrincipalContext());

        $this->singleton(Authenticator::class, fn() => new Authenticator(
            accounts: $this->accountProvider(),
            accountStore: $this->accountStore(),
            passwords: $this->passwordVerifier(),
            sessions: $this->service(SessionManager::class),
            ids: $this->idGenerator(),
            audit: $this->auditStore(),
            lockouts: $this->service(LockoutManager::class),
            clock: $this->clock(),
        ));

        $this->singleton(AuthServices::class, fn() => new AuthServices(
            authenticator: $this->service(Authenticator::class),
            sessions: $this->service(SessionManager::class),
            passwordResets: $this->service(PasswordResetManager::class),
            emailVerification: $this->service(EmailVerificationManager::class),
            passwordChanges: $this->service(PasswordChangeManager::class),
            passwordless: $this->service(PasswordlessManager::class),
            rememberMe: $this->service(RememberMeManager::class),
            tokens: $this->service(TokenAuthManager::class),
            mfa: $this->service(MfaManager::class),
            otp: $this->service(OtpManager::class),
            passkeys: $this->service(PasskeyManager::class),
            accounts: $this->service(AccountManager::class),
            devices: $this->service(DeviceManager::class),
            roles: $this->service(RoleManager::class),
            permissions: $this->service(PermissionManager::class),
            delegation: $this->service(DelegationManager::class),
            authorizer: $this->service(PermissionAuthorizer::class),
            principals: $this->service(CurrentPrincipalContext::class),
        ));

        $this->singleton(AuthActions::class, fn() => new AuthActions(
            services: $this->service(AuthServices::class),
            accounts: $this->accountProvider(),
            passwords: $this->passwordHasher(),
            policy: $this->passwordPolicy(),
        ));
        $this->alias('foundation.auth.actions', AuthActions::class);

        $this->singleton(AuthManager::class, fn() => new AuthManager(
            services: $this->service(AuthServices::class),
            config: $this->app->config(),
            drivers: $this->service(AuthDriverResolver::class)->summary(),
        ));
    }
}
