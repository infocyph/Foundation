<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\AuthLayer\Account\AccountManager;
use Infocyph\AuthLayer\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\AuthLayer\Authentication\Login\AuthenticatorInterface;
use Infocyph\AuthLayer\Authentication\PasswordChange\PasswordChangeManager;
use Infocyph\AuthLayer\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\AuthLayer\Authentication\Passwordless\PasswordlessManager;
use Infocyph\AuthLayer\Authentication\RememberMe\RememberMeManager;
use Infocyph\AuthLayer\Authentication\Session\SessionManager;
use Infocyph\AuthLayer\Authentication\TokenAuth\TokenAuthManager;
use Infocyph\AuthLayer\Authorization\Gate\PermissionAuthorizer;
use Infocyph\AuthLayer\Authorization\Grant\DelegationManager;
use Infocyph\AuthLayer\Authorization\Permission\PermissionManager;
use Infocyph\AuthLayer\Authorization\Role\RoleManager;
use Infocyph\AuthLayer\Device\DeviceManager;
use Infocyph\AuthLayer\Mfa\MfaManager;
use Infocyph\AuthLayer\Passkey\PasskeyManager;
use Infocyph\AuthLayer\Principal\CurrentPrincipalContext;

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
