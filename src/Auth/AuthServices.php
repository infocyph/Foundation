<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Account\AccountManager;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\Foundation\Auth\Authentication\Impersonation\ImpersonationManager;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
use Infocyph\Foundation\Auth\Authentication\Login\AuthenticatorInterface;
use Infocyph\Foundation\Auth\Authentication\PasswordChange\PasswordChangeManager;
use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessManager;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberMeManager;
use Infocyph\Foundation\Auth\Authentication\Session\SessionManager;
use Infocyph\Foundation\Auth\Authentication\StepUp\StepUpManager;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\TokenAuthManager;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Authorization\Gate\Gate;
use Infocyph\Foundation\Auth\Authorization\Grant\DelegationManager;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionManager;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Device\DeviceManager;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\Passkey\PasskeyManager;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;

final readonly class AuthServices
{
    public function __construct(
        private Application $app,
    ) {}

    public function accountProvider(): AccountProviderInterface
    {
        return $this->app->make(AccountProviderInterface::class);
    }

    public function accounts(): AccountManager
    {
        return $this->app->make(AccountManager::class);
    }

    public function authenticator(): AuthenticatorInterface
    {
        return $this->app->make(Authenticator::class);
    }

    public function authorizer(): AuthorizerInterface
    {
        return $this->app->make(AuthorizerInterface::class);
    }

    public function delegation(): DelegationManager
    {
        return $this->app->make(DelegationManager::class);
    }

    public function devices(): DeviceManager
    {
        return $this->app->make(DeviceManager::class);
    }

    public function emailVerification(): EmailVerificationManager
    {
        return $this->app->make(EmailVerificationManager::class);
    }

    public function gate(): Gate
    {
        return $this->app->make(Gate::class);
    }

    public function impersonation(): ImpersonationManager
    {
        return $this->app->make(ImpersonationManager::class);
    }

    public function lockouts(): LockoutManager
    {
        return $this->app->make(LockoutManager::class);
    }

    public function mfa(): MfaManager
    {
        return $this->app->make(MfaManager::class);
    }

    public function passkeys(): PasskeyManager
    {
        return $this->app->make(PasskeyManager::class);
    }

    public function passwordChanges(): PasswordChangeManager
    {
        return $this->app->make(PasswordChangeManager::class);
    }

    public function passwordHasher(): PasswordHasherInterface
    {
        return $this->app->make(PasswordHasherInterface::class);
    }

    public function passwordless(): PasswordlessManager
    {
        return $this->app->make(PasswordlessManager::class);
    }

    public function passwordPolicy(): PasswordPolicyInterface
    {
        return $this->app->make(PasswordPolicyInterface::class);
    }

    public function passwordResets(): PasswordResetManager
    {
        return $this->app->make(PasswordResetManager::class);
    }

    public function permissions(): PermissionManager
    {
        return $this->app->make(PermissionManager::class);
    }

    public function principals(): CurrentPrincipalContext
    {
        return $this->app->make(CurrentPrincipalContext::class);
    }

    public function rememberMe(): RememberMeManager
    {
        return $this->app->make(RememberMeManager::class);
    }

    public function roles(): RoleManager
    {
        return $this->app->make(RoleManager::class);
    }

    public function sessions(): SessionManager
    {
        return $this->app->make(SessionManager::class);
    }

    public function stepUp(): StepUpManager
    {
        return $this->app->make(StepUpManager::class);
    }

    public function tokens(): TokenAuthManager
    {
        return $this->app->make(TokenAuthManager::class);
    }
}
