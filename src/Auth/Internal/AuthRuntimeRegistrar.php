<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\AuthLayer\Account\AccountManager;
use Infocyph\AuthLayer\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\AuthLayer\Authentication\Login\Authenticator;
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
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\AuthLayer\Contract\Security\PasswordVerifierInterface;
use Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface;
use Infocyph\AuthLayer\Contract\Storage\AccountStoreInterface;
use Infocyph\AuthLayer\Contract\Storage\AuditEventStoreInterface;
use Infocyph\AuthLayer\Device\DeviceManager;
use Infocyph\AuthLayer\Mfa\MfaManager;
use Infocyph\AuthLayer\Passkey\PasskeyManager;
use Infocyph\AuthLayer\Principal\CurrentPrincipalContext;
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
            lockouts: $container->get(\Infocyph\AuthLayer\Authentication\Lockout\LockoutManager::class),
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
            passwords: $container->get(\Infocyph\AuthLayer\Contract\Security\PasswordHasherInterface::class),
            policy: $container->get(\Infocyph\AuthLayer\Contract\Security\PasswordPolicyInterface::class),
        ), LifetimeEnum::Singleton);
        $container->bind('foundation.auth.actions', fn() => $container->get(AuthActions::class), LifetimeEnum::Singleton);

        $container->bind(AuthManager::class, fn() => new AuthManager(
            services: $container->get(AuthServices::class),
            config: $app->config(),
            drivers: $container->get(AuthDriverResolver::class)->summary(),
        ), LifetimeEnum::Singleton);
    }
}
