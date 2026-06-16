<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Auth\Account\AccountManager;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\Foundation\Auth\Authentication\Login\AuthenticatorInterface;
use Infocyph\Foundation\Auth\Authentication\PasswordChange\PasswordChangeManager;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessManager;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberMeManager;
use Infocyph\Foundation\Auth\Authentication\Session\SessionManager;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\TokenAuthManager;
use Infocyph\Foundation\Auth\Authorization\Gate\PermissionAuthorizer;
use Infocyph\Foundation\Auth\Authorization\Grant\DelegationManager;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionManager;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\Device\DeviceManager;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\Passkey\PasskeyManager;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;

final readonly class AuthServices
{
    public function __construct(
        public AuthenticatorInterface $authenticator,
        public SessionManager $sessions,
        public PasswordResetManager $passwordResets,
        public EmailVerificationManager $emailVerification,
        public PasswordChangeManager $passwordChanges,
        public PasswordlessManager $passwordless,
        public RememberMeManager $rememberMe,
        public TokenAuthManager $tokens,
        public MfaManager $mfa,
        public PasskeyManager $passkeys,
        public AccountManager $accounts,
        public DeviceManager $devices,
        public RoleManager $roles,
        public PermissionManager $permissions,
        public DelegationManager $delegation,
        public PermissionAuthorizer $authorizer,
        public CurrentPrincipalContext $principals,
    ) {}
}
