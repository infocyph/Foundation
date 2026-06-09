<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\AuthLayer\Account\AccountManager;
use Infocyph\AuthLayer\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\AuthLayer\Authentication\EmailVerification\EmailVerificationTokenServiceInterface;
use Infocyph\AuthLayer\Authentication\Lockout\LockoutConfig;
use Infocyph\AuthLayer\Authentication\Lockout\LockoutManager;
use Infocyph\AuthLayer\Authentication\Login\Authenticator;
use Infocyph\AuthLayer\Authentication\PasswordChange\PasswordChangeManager;
use Infocyph\AuthLayer\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\AuthLayer\Authentication\PasswordReset\PasswordResetTokenServiceInterface;
use Infocyph\AuthLayer\Authentication\Passwordless\PasswordlessManager;
use Infocyph\AuthLayer\Authentication\Passwordless\PasswordlessTokenServiceInterface;
use Infocyph\AuthLayer\Authentication\RememberMe\RememberMeManager;
use Infocyph\AuthLayer\Authentication\RememberMe\RememberTokenServiceInterface;
use Infocyph\AuthLayer\Authentication\Session\SessionConfig;
use Infocyph\AuthLayer\Authentication\Session\SessionManager;
use Infocyph\AuthLayer\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\AuthLayer\Authentication\TokenAuth\TokenAuthManager;
use Infocyph\AuthLayer\Authorization\Gate\PermissionAuthorizer;
use Infocyph\AuthLayer\Authorization\Grant\DelegationManager;
use Infocyph\AuthLayer\Authorization\Grant\GrantResolver;
use Infocyph\AuthLayer\Authorization\Grant\GrantStoreInterface;
use Infocyph\AuthLayer\Authorization\Permission\PermissionAssignmentStoreInterface;
use Infocyph\AuthLayer\Authorization\Permission\PermissionManager;
use Infocyph\AuthLayer\Authorization\Permission\PermissionResolver;
use Infocyph\AuthLayer\Authorization\Permission\PermissionStoreInterface;
use Infocyph\AuthLayer\Authorization\Role\RoleAssignmentStoreInterface;
use Infocyph\AuthLayer\Authorization\Role\RoleManager;
use Infocyph\AuthLayer\Authorization\Role\RolePermissionResolver;
use Infocyph\AuthLayer\Authorization\Role\RoleStoreInterface;
use Infocyph\AuthLayer\Contract\Cache\CounterStoreInterface;
use Infocyph\AuthLayer\Contract\Cache\TtlStoreInterface;
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\AuthLayer\Contract\Notification\AuthNotifierInterface;
use Infocyph\AuthLayer\Contract\Security\AccessTokenServiceInterface;
use Infocyph\AuthLayer\Contract\Security\PasswordHasherInterface;
use Infocyph\AuthLayer\Contract\Security\PasswordPolicyInterface;
use Infocyph\AuthLayer\Contract\Security\PasswordVerifierInterface;
use Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface;
use Infocyph\AuthLayer\Contract\Storage\AccountStoreInterface;
use Infocyph\AuthLayer\Contract\Storage\AuditEventStoreInterface;
use Infocyph\AuthLayer\Contract\Storage\EmailVerificationStoreInterface;
use Infocyph\AuthLayer\Contract\Storage\LockoutStoreInterface;
use Infocyph\AuthLayer\Contract\Storage\PasswordResetStoreInterface;
use Infocyph\AuthLayer\Contract\Storage\RefreshTokenStoreInterface;
use Infocyph\AuthLayer\Contract\Storage\RememberTokenStoreInterface;
use Infocyph\AuthLayer\Contract\Storage\SessionStoreInterface;
use Infocyph\AuthLayer\Device\DeviceManager;
use Infocyph\AuthLayer\Device\DeviceStoreInterface;
use Infocyph\AuthLayer\Mfa\MfaFactorStoreInterface;
use Infocyph\AuthLayer\Mfa\MfaManager;
use Infocyph\AuthLayer\Mfa\MfaVerifierInterface;
use Infocyph\AuthLayer\Mfa\RecoveryCodeServiceInterface;
use Infocyph\AuthLayer\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\AuthLayer\Passkey\PasskeyManager;
use Infocyph\AuthLayer\Passkey\PasskeyServiceInterface;
use Infocyph\AuthLayer\Principal\CurrentPrincipalContext;
use Infocyph\AuthLayer\Support\AcceptAllPasswordPolicy;
use Infocyph\AuthLayer\Support\ArrayTtlStore;
use Infocyph\AuthLayer\Support\CollectingAuthNotifier;
use Infocyph\AuthLayer\Support\InMemoryAccountStore;
use Infocyph\AuthLayer\Support\InMemoryAuditEventStore;
use Infocyph\AuthLayer\Support\InMemoryCounterStore;
use Infocyph\AuthLayer\Support\InMemoryDeviceStore;
use Infocyph\AuthLayer\Support\InMemoryEmailVerificationStore;
use Infocyph\AuthLayer\Support\InMemoryGrantStore;
use Infocyph\AuthLayer\Support\InMemoryLockoutStore;
use Infocyph\AuthLayer\Support\InMemoryMfaFactorStore;
use Infocyph\AuthLayer\Support\InMemoryPasskeyCredentialStore;
use Infocyph\AuthLayer\Support\InMemoryPasswordResetStore;
use Infocyph\AuthLayer\Support\InMemoryPermissionStore;
use Infocyph\AuthLayer\Support\InMemoryRefreshTokenStore;
use Infocyph\AuthLayer\Support\InMemoryRememberTokenStore;
use Infocyph\AuthLayer\Support\InMemoryRoleStore;
use Infocyph\AuthLayer\Support\InMemorySessionStore;
use Infocyph\AuthLayer\Support\SystemClock;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Auth\CacheLayer\CacheLayerCounterStore;
use Infocyph\Foundation\Auth\CacheLayer\CacheLayerTtlStore;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthCacheDriver;
use Infocyph\Foundation\Auth\Driver\AuthMfaDriver;
use Infocyph\Foundation\Auth\Driver\AuthNotificationDriver;
use Infocyph\Foundation\Auth\Driver\AuthPasskeyDriver;
use Infocyph\Foundation\Auth\Driver\AuthPasswordDriver;
use Infocyph\Foundation\Auth\Driver\AuthStorageDriver;
use Infocyph\Foundation\Auth\Driver\AuthTokenDriver;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptAccessTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptEmailVerificationTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptPasswordHasher;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptPasswordResetTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptPasswordVerifier;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptPasswordlessTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptRefreshTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptRememberTokenService;
use Infocyph\Foundation\Auth\Epicrypt\EpicryptTokenFactory;
use Infocyph\Foundation\Auth\Support\HmacTokenCodec;
use Infocyph\Foundation\Auth\Support\InMemoryPasskeyService;
use Infocyph\Foundation\Auth\Support\InMemoryRecoveryCodeService;
use Infocyph\Foundation\Auth\Support\NativePasswordHasher;
use Infocyph\Foundation\Auth\Support\NativePasswordVerifier;
use Infocyph\Foundation\Auth\Support\SimpleAccessTokenService;
use Infocyph\Foundation\Auth\Support\SimpleEmailVerificationTokenService;
use Infocyph\Foundation\Auth\Support\SimpleMfaVerifier;
use Infocyph\Foundation\Auth\Support\SimplePasswordResetTokenService;
use Infocyph\Foundation\Auth\Support\SimplePasswordlessTokenService;
use Infocyph\Foundation\Auth\Support\SimpleRefreshTokenService;
use Infocyph\Foundation\Auth\Support\SimpleRememberTokenService;
use Infocyph\Foundation\Auth\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Epicrypt\Password\PasswordHasher as EpicryptPasswordEngine;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();
        $drivers = new AuthDriverResolver($app->config());

        $this->registerCore($container, $drivers);
        $this->guardProductionDrivers($app, $drivers);
        $this->registerStores($app, $container, $drivers);
        $this->registerSecurity($app, $container, $drivers);
        $this->registerManagers($app, $container);
        $this->registerAuthorization($container);
        $this->registerAuthServices($container);
    }

    private function registerAuthServices(Container $container): void
    {
        $container->bind(CurrentPrincipalContext::class, fn() => new CurrentPrincipalContext(), LifetimeEnum::Singleton);

        $container->bind(Authenticator::class, fn() => new Authenticator(
            accounts: $container->get(AccountProviderInterface::class),
            accountStore: $container->get(AccountStoreInterface::class),
            passwords: $container->get(PasswordVerifierInterface::class),
            sessions: $container->get(SessionManager::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            lockouts: $container->get(LockoutManager::class),
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

        $container->bind(AuthManager::class, fn() => new AuthManager(
            services: $container->get(AuthServices::class),
            drivers: $container->get(AuthDriverResolver::class)->summary(),
        ), LifetimeEnum::Singleton);
    }

    private function registerAuthorization(Container $container): void
    {
        $container->bind(RoleManager::class, fn() => new RoleManager(
            roles: $container->get(RoleStoreInterface::class),
            assignments: $container->get(RoleAssignmentStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(PermissionManager::class, fn() => new PermissionManager(
            permissions: $container->get(PermissionStoreInterface::class),
            assignments: $container->get(PermissionAssignmentStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(DelegationManager::class, fn() => new DelegationManager(
            grants: $container->get(GrantStoreInterface::class),
            audit: $container->get(AuditEventStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(PermissionResolver::class, fn() => new PermissionResolver(
            permissions: $container->get(PermissionStoreInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(RolePermissionResolver::class, fn() => new RolePermissionResolver(
            roles: $container->get(RoleStoreInterface::class),
            permissions: $container->get(PermissionStoreInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(GrantResolver::class, fn() => new GrantResolver(
            grants: $container->get(GrantStoreInterface::class),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(PermissionAuthorizer::class, fn() => new PermissionAuthorizer(
            permissions: $container->get(PermissionResolver::class),
            rolePermissions: $container->get(RolePermissionResolver::class),
            grants: $container->get(GrantResolver::class),
        ), LifetimeEnum::Singleton);
    }

    private function registerCore(Container $container, AuthDriverResolver $drivers): void
    {
        $container->bind(ClockInterface::class, new SystemClock(), LifetimeEnum::Singleton);
        $container->bind(AuthDriverResolver::class, $drivers, LifetimeEnum::Singleton);
        $container->bind(AuthIdGeneratorInterface::class, new UidAuthIdGenerator(), LifetimeEnum::Singleton);
        $container->bind(PasswordPolicyInterface::class, new AcceptAllPasswordPolicy(), LifetimeEnum::Singleton);
    }

    private function registerManagers(Application $app, Container $container): void
    {
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
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $container->bind(DeviceManager::class, fn() => new DeviceManager(
            devices: $container->get(DeviceStoreInterface::class),
            ids: $container->get(AuthIdGeneratorInterface::class),
            clock: $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);
    }

    private function registerSecurity(Application $app, Container $container, AuthDriverResolver $drivers): void
    {
        $container->bind(HmacTokenCodec::class, fn() => new HmacTokenCodec(
            $this->tokenSecret($app),
        ), LifetimeEnum::Singleton);

        $this->registerPasswordSecurityDriver($app, $container, $drivers);
        $this->registerTokenSecurityDriver($app, $container, $drivers);
        $this->registerMfaDriver($app, $container, $drivers);
        $this->registerPasskeyDriver($app, $container, $drivers);
    }

    private function registerStores(Application $app, Container $container, AuthDriverResolver $drivers): void
    {
        if ($drivers->storage() !== AuthStorageDriver::MEMORY) {
            throw new ConfigurationException(sprintf(
                'Auth storage driver "%s" is not implemented yet.',
                $drivers->storage()->value,
            ));
        }

        $container->bind(AccountStoreInterface::class, fn() => new InMemoryAccountStore(), LifetimeEnum::Singleton);
        $container->bind(AccountProviderInterface::class, fn() => $container->get(AccountStoreInterface::class), LifetimeEnum::Singleton);
        $container->bind(SessionStoreInterface::class, fn() => new InMemorySessionStore(), LifetimeEnum::Singleton);
        $container->bind(PasswordResetStoreInterface::class, fn() => new InMemoryPasswordResetStore(), LifetimeEnum::Singleton);
        $container->bind(EmailVerificationStoreInterface::class, fn() => new InMemoryEmailVerificationStore(), LifetimeEnum::Singleton);
        $container->bind(RememberTokenStoreInterface::class, fn() => new InMemoryRememberTokenStore(), LifetimeEnum::Singleton);
        $container->bind(RefreshTokenStoreInterface::class, fn() => new InMemoryRefreshTokenStore(), LifetimeEnum::Singleton);
        $container->bind(MfaFactorStoreInterface::class, fn() => new InMemoryMfaFactorStore(), LifetimeEnum::Singleton);
        $container->bind(PasskeyCredentialStoreInterface::class, fn() => new InMemoryPasskeyCredentialStore(), LifetimeEnum::Singleton);
        $container->bind(RoleStoreInterface::class, fn() => new InMemoryRoleStore(), LifetimeEnum::Singleton);
        $container->bind(RoleAssignmentStoreInterface::class, fn() => $container->get(RoleStoreInterface::class), LifetimeEnum::Singleton);
        $container->bind(PermissionStoreInterface::class, fn() => new InMemoryPermissionStore(), LifetimeEnum::Singleton);
        $container->bind(PermissionAssignmentStoreInterface::class, fn() => $container->get(PermissionStoreInterface::class), LifetimeEnum::Singleton);
        $container->bind(GrantStoreInterface::class, fn() => new InMemoryGrantStore(
            $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);
        $container->bind(DeviceStoreInterface::class, fn() => new InMemoryDeviceStore(), LifetimeEnum::Singleton);
        $container->bind(AuditEventStoreInterface::class, fn() => new InMemoryAuditEventStore(), LifetimeEnum::Singleton);
        $container->bind(LockoutStoreInterface::class, fn() => new InMemoryLockoutStore(
            $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);

        $this->registerCacheDriver($app, $container, $drivers);
        $this->registerNotificationDriver($container, $drivers);
    }

    private function tokenSecret(Application $app): string
    {
        $secret = $app->config()->get('auth.token_secret', 'foundation-dev-secret');

        $resolved = is_string($secret) && $secret !== ''
            ? $secret
            : 'foundation-dev-secret';

        if (
            $resolved === 'foundation-dev-secret'
            && $app->config()->isProduction()
        ) {
            throw new ConfigurationException('auth.token_secret must be configured in production.');
        }

        return $resolved;
    }

    private function guardProductionDrivers(Application $app, AuthDriverResolver $drivers): void
    {
        if (!$app->config()->isProduction()) {
            return;
        }

        if ($drivers->tokens() === AuthTokenDriver::SIMPLE) {
            throw new ConfigurationException('auth.drivers.tokens must not be "simple" in production.');
        }

        if ($drivers->storage() === AuthStorageDriver::MEMORY) {
            throw new ConfigurationException('auth.drivers.storage must not be "memory" in production.');
        }

        if ($drivers->mfa() === AuthMfaDriver::SIMPLE) {
            throw new ConfigurationException('auth.drivers.mfa must not be "simple" in production.');
        }

        if ($drivers->notifications() === AuthNotificationDriver::COLLECT) {
            throw new ConfigurationException('auth.drivers.notifications must not be "collect" in production.');
        }
    }

    private function registerCacheDriver(Application $app, Container $container, AuthDriverResolver $drivers): void
    {
        if ($drivers->cache() === AuthCacheDriver::CACHELAYER) {
            $container->bind(CacheInterface::class, fn() => $app->cache()->store(
                (string) $app->config()->get('auth.cachelayer.store', (string) $app->config()->get('cache.default', 'memory')),
            ), LifetimeEnum::Singleton);
            $container->bind(CounterStoreInterface::class, fn() => new CacheLayerCounterStore(
                $container->get(CacheInterface::class),
            ), LifetimeEnum::Singleton);
            $container->bind(TtlStoreInterface::class, fn() => new CacheLayerTtlStore(
                $container->get(CacheInterface::class),
            ), LifetimeEnum::Singleton);

            return;
        }

        $container->bind(CounterStoreInterface::class, fn() => new InMemoryCounterStore(
            $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);
        $container->bind(TtlStoreInterface::class, fn() => new ArrayTtlStore(
            $container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);
    }

    private function registerMfaDriver(Application $app, Container $container, AuthDriverResolver $drivers): void
    {
        if ($drivers->mfa() !== AuthMfaDriver::SIMPLE) {
            throw new ConfigurationException(sprintf(
                'Auth MFA driver "%s" is not implemented yet.',
                $drivers->mfa()->value,
            ));
        }

        $container->bind(MfaVerifierInterface::class, fn() => new SimpleMfaVerifier(
            (string) $app->config()->get('auth.mfa_default_code', '000000'),
        ), LifetimeEnum::Singleton);

        $container->bind(RecoveryCodeServiceInterface::class, fn() => new InMemoryRecoveryCodeService(), LifetimeEnum::Singleton);
    }

    private function registerNotificationDriver(Container $container, AuthDriverResolver $drivers): void
    {
        if ($drivers->notifications() !== AuthNotificationDriver::COLLECT) {
            throw new ConfigurationException(sprintf(
                'Auth notification driver "%s" is not implemented yet.',
                $drivers->notifications()->value,
            ));
        }

        $container->bind(AuthNotifierInterface::class, fn() => new CollectingAuthNotifier(), LifetimeEnum::Singleton);
    }

    private function registerPasskeyDriver(Application $app, Container $container, AuthDriverResolver $drivers): void
    {
        if ($drivers->passkey() !== AuthPasskeyDriver::MEMORY) {
            throw new ConfigurationException(sprintf(
                'Auth passkey driver "%s" is not implemented yet.',
                $drivers->passkey()->value,
            ));
        }

        $container->bind(PasskeyServiceInterface::class, fn() => new InMemoryPasskeyService(
            $container->get(PasskeyCredentialStoreInterface::class),
            $container->get(ClockInterface::class),
            (int) $app->config()->get('auth.passkey_challenge_ttl', 300),
        ), LifetimeEnum::Singleton);
    }

    private function registerPasswordSecurityDriver(Application $app, Container $container, AuthDriverResolver $drivers): void
    {
        if ($drivers->passwords() === AuthPasswordDriver::EPICRYPT) {
            $options = $this->epicryptPasswordOptions($app);

            $container->bind(PasswordHasherInterface::class, fn() => new EpicryptPasswordHasher(
                hasher: new EpicryptPasswordEngine(),
                options: $options,
            ), LifetimeEnum::Singleton);

            $container->bind(PasswordVerifierInterface::class, fn() => new EpicryptPasswordVerifier(
                hasher: new EpicryptPasswordEngine(),
                options: $options,
            ), LifetimeEnum::Singleton);

            return;
        }

        $container->bind(PasswordHasherInterface::class, fn() => new NativePasswordHasher(), LifetimeEnum::Singleton);

        $container->bind(PasswordVerifierInterface::class, fn() => new NativePasswordVerifier(
            $container->get(PasswordHasherInterface::class),
        ), LifetimeEnum::Singleton);
    }

    private function registerTokenSecurityDriver(Application $app, Container $container, AuthDriverResolver $drivers): void
    {
        if ($drivers->tokens() === AuthTokenDriver::EPICRYPT) {
            $container->bind(EpicryptTokenFactory::class, fn() => new EpicryptTokenFactory(
                key: $this->tokenSecret($app),
                clock: $container->get(ClockInterface::class),
                issuer: $this->epicryptTokenIssuer($app),
                audience: $this->epicryptTokenAudience($app),
                leewaySeconds: $this->epicryptTokenLeeway($app),
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

            $container->bind(RememberTokenServiceInterface::class, fn() => new EpicryptRememberTokenService(
                $container->get(EpicryptTokenFactory::class),
                $container->get(RememberTokenStoreInterface::class),
                (int) $app->config()->get('auth.remember_me_ttl', 2592000),
            ), LifetimeEnum::Singleton);

            return;
        }

        if ($drivers->tokens() !== AuthTokenDriver::SIMPLE) {
            throw new ConfigurationException(sprintf(
                'Auth token driver "%s" is not implemented yet.',
                $drivers->tokens()->value,
            ));
        }

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

    /**
     * @return array<string, mixed>
     */
    private function epicryptPasswordOptions(Application $app): array
    {
        $options = $app->config()->get('auth.epicrypt.password', []);

        return is_array($options) ? $options : [];
    }

    private function epicryptTokenAudience(Application $app): ?string
    {
        return $this->normalizedOptionalString($app->config()->get('auth.epicrypt.tokens.audience'));
    }

    private function epicryptTokenIssuer(Application $app): ?string
    {
        return $this->normalizedOptionalString($app->config()->get('auth.epicrypt.tokens.issuer'));
    }

    private function epicryptTokenLeeway(Application $app): int
    {
        return max(0, (int) $app->config()->get('auth.epicrypt.tokens.leeway_seconds', 0));
    }

    private function normalizedOptionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

}
