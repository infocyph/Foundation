<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Authorization\Grant\GrantStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionAssignmentStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleAssignmentStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\EmailVerificationStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\LockoutStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\PasswordResetStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\RefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\RememberTokenStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;
use Infocyph\Foundation\Auth\Device\DeviceStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Support\InMemoryAccountStore;
use Infocyph\Foundation\Auth\Support\InMemoryAuditEventStore;
use Infocyph\Foundation\Auth\Support\InMemoryDeviceStore;
use Infocyph\Foundation\Auth\Support\InMemoryEmailVerificationStore;
use Infocyph\Foundation\Auth\Support\InMemoryGrantStore;
use Infocyph\Foundation\Auth\Support\InMemoryLockoutStore;
use Infocyph\Foundation\Auth\Support\InMemoryMfaFactorStore;
use Infocyph\Foundation\Auth\Support\InMemoryPasskeyCredentialStore;
use Infocyph\Foundation\Auth\Support\InMemoryPasswordResetStore;
use Infocyph\Foundation\Auth\Support\InMemoryPermissionStore;
use Infocyph\Foundation\Auth\Support\InMemoryRefreshTokenStore;
use Infocyph\Foundation\Auth\Support\InMemoryRememberTokenStore;
use Infocyph\Foundation\Auth\Support\InMemoryRoleStore;
use Infocyph\Foundation\Auth\Support\InMemorySessionStore;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerAccountStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerAuditEventStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerDeviceStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerEmailVerificationStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerGrantStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerLockoutStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerMfaFactorStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerPasskeyCredentialStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerPasswordResetStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerPermissionStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerRefreshTokenStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerRememberTokenStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerRoleStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerSessionStore;
use Infocyph\Foundation\Auth\Driver\AuthStorageDriver;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthStoreRegistrar
{
    public function __construct(
        private Application $app,
        private Container $container,
    ) {}

    public function register(AuthStorageDriver $driver): void
    {
        if ($driver === AuthStorageDriver::DBLAYER) {
            $this->registerDBLayerStores();

            return;
        }

        if ($driver === AuthStorageDriver::MEMORY) {
            $this->registerMemoryStores();

            return;
        }

        throw new ConfigurationException(sprintf(
            'Auth storage driver "%s" is not implemented yet.',
            $driver->value,
        ));
    }

    private function registerDBLayerStores(): void
    {
        $container = $this->container;
        $connection = $this->authConnection();

        $container->bind(AccountStoreInterface::class, fn() => new DBLayerAccountStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(AccountProviderInterface::class, fn() => $container->get(AccountStoreInterface::class), LifetimeEnum::Singleton);
        $container->bind(SessionStoreInterface::class, fn() => new DBLayerSessionStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(PasswordResetStoreInterface::class, fn() => new DBLayerPasswordResetStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $container->get(ClockInterface::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(EmailVerificationStoreInterface::class, fn() => new DBLayerEmailVerificationStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $container->get(ClockInterface::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(RememberTokenStoreInterface::class, fn() => new DBLayerRememberTokenStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $container->get(ClockInterface::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(RefreshTokenStoreInterface::class, fn() => new DBLayerRefreshTokenStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $container->get(ClockInterface::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(MfaFactorStoreInterface::class, fn() => new DBLayerMfaFactorStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(PasskeyCredentialStoreInterface::class, fn() => new DBLayerPasskeyCredentialStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $container->get(ClockInterface::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(RoleStoreInterface::class, fn() => new DBLayerRoleStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $container->get(ClockInterface::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(RoleAssignmentStoreInterface::class, fn() => $container->get(RoleStoreInterface::class), LifetimeEnum::Singleton);
        $container->bind(PermissionStoreInterface::class, fn() => new DBLayerPermissionStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $container->get(ClockInterface::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(PermissionAssignmentStoreInterface::class, fn() => $container->get(PermissionStoreInterface::class), LifetimeEnum::Singleton);
        $container->bind(GrantStoreInterface::class, fn() => new DBLayerGrantStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $container->get(ClockInterface::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(DeviceStoreInterface::class, fn() => new DBLayerDeviceStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $container->get(ClockInterface::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(AuditEventStoreInterface::class, fn() => new DBLayerAuditEventStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $connection,
        ), LifetimeEnum::Singleton);
        $container->bind(LockoutStoreInterface::class, fn() => new DBLayerLockoutStore(
            $container->get(DBLayerFactory::class),
            $container->get(AuthTables::class),
            $container->get(ClockInterface::class),
            $connection,
        ), LifetimeEnum::Singleton);
    }

    private function registerMemoryStores(): void
    {
        $container = $this->container;

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
    }

    private function authConnection(): ?string
    {
        $configured = $this->app->config()->get('auth.dblayer.connection');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $default = $this->app->config()->get('database.default');

        return is_string($default) && $default !== ''
            ? $default
            : null;
    }
}
