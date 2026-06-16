<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Account\AccountManager;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\Foundation\Auth\Authentication\Login\Authenticator;
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
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Device\DeviceManager;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\Passkey\PasskeyManager;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthRuntimeRegistrar
{
    public function __construct(
        private Application $app,
        private Container $container,
    ) {}

    public function register(): void
    {
        $app = $this->app;
        $container = $this->container;

        $container->bind(CurrentPrincipalContext::class, fn() => new CurrentPrincipalContext(), LifetimeEnum::Singleton);

        $container->bind(Authenticator::class, fn() => new Authenticator(
            accounts: $container->get(AccountProviderInterface::class),
            accountStore: $container->get(AccountStoreInterface::class),
            passwords: $container->get(PasswordVerifierInterface::class),
            sessions: $container->get(SessionManager::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            lockouts: $container->get(\Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager::class),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(AuthServices::class, fn() => new AuthServices(
            authenticator: $container->get(Authenticator::class),
            sessions: $container->get(SessionManager::class),
            passwordResets: $container->get(PasswordResetManager::class),
            emailVerification: $container->get(EmailVerificationManager::class),
            passwordChanges: $container->get(PasswordChangeManager::class),
            passwordless: $container->get(PasswordlessManager::class),
            rememberMe: $container->get(RememberMeManager::class),
            tokens: $container->get(TokenAuthManager::class),
            mfa: $container->get(MfaManager::class),
            passkeys: $container->get(PasskeyManager::class),
            accounts: $container->get(AccountManager::class),
            devices: $container->get(DeviceManager::class),
            roles: $container->get(RoleManager::class),
            permissions: $container->get(PermissionManager::class),
            delegation: $container->get(DelegationManager::class),
            authorizer: $container->get(PermissionAuthorizer::class),
            principals: $container->get(CurrentPrincipalContext::class),
        ), LifetimeEnum::Singleton);

        $container->bind(AuthActions::class, fn() => new AuthActions(
            services: $container->get(AuthServices::class),
            accounts: $container->get(AccountProviderInterface::class),
            passwords: $container->get(\Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface::class),
            policy: $container->get(\Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface::class),
        ), LifetimeEnum::Singleton);
        $container->bind('foundation.auth.actions', fn() => $container->get(AuthActions::class), LifetimeEnum::Singleton);

        $container->bind(AuthManager::class, fn() => new AuthManager(
            services: $container->get(AuthServices::class),
            config: $app->config(),
            drivers: $container->get(AuthDriverResolver::class)->summary(),
        ), LifetimeEnum::Singleton);
    }
}
