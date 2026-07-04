<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Adapter\DBLayer\{
    DBLayerAccountStore,
    DBLayerAuditEventStore,
    DBLayerDeviceStore,
    DBLayerEmailVerificationStore,
    DBLayerGrantStore,
    DBLayerLockoutStore,
    DBLayerMfaFactorStore,
    DBLayerPasskeyCredentialStore,
    DBLayerPasswordResetStore,
    DBLayerPermissionStore,
    DBLayerRefreshTokenStore,
    DBLayerRememberTokenStore,
    DBLayerRoleStore,
    DBLayerSessionStore
};
use Infocyph\Foundation\Auth\Authorization\Grant\GrantStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Permission\{PermissionAssignmentStoreInterface, PermissionStoreInterface};
use Infocyph\Foundation\Auth\Authorization\Role\{RoleAssignmentStoreInterface, RoleStoreInterface};
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
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
use Infocyph\Foundation\Auth\Device\DeviceStoreInterface;
use Infocyph\Foundation\Auth\Driver\AuthStorageDriver;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Support\{
    InMemoryAccountStore,
    InMemoryAuditEventStore,
    InMemoryDeviceStore,
    InMemoryEmailVerificationStore,
    InMemoryGrantStore,
    InMemoryLockoutStore,
    InMemoryMfaFactorStore,
    InMemoryPasskeyCredentialStore,
    InMemoryPasswordResetStore,
    InMemoryPermissionStore,
    InMemoryRefreshTokenStore,
    InMemoryRememberTokenStore,
    InMemoryRoleStore,
    InMemorySessionStore
};
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class AuthStoreRegistrar extends AbstractAuthRegistrar
{
    public function register(AuthStorageDriver $driver): void
    {
        if ($driver === AuthStorageDriver::DBLAYER) {
            $this->registerDBLayerStores();

            return;
        }
        $this->registerMemoryStores();
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

    /**
     * @param class-string $storeClass
     */
    private function bindClockedDbStore(string $id, string $storeClass, ?string $connection): void
    {
        $this->singleton($id, fn() => new $storeClass(
            $this->service(DBLayerFactory::class),
            $this->service(AuthTables::class),
            $this->service(ClockInterface::class),
            $connection,
        ));
    }

    /**
     * @param class-string $storeClass
     */
    private function bindPlainDbStore(string $id, string $storeClass, ?string $connection): void
    {
        $this->singleton($id, fn() => new $storeClass(
            $this->service(DBLayerFactory::class),
            $this->service(AuthTables::class),
            $connection,
        ));
    }

    /**
     * @return array<string, class-string>
     */
    private function clockedDbStores(): array
    {
        return [
            PasswordResetStoreInterface::class => DBLayerPasswordResetStore::class,
            EmailVerificationStoreInterface::class => DBLayerEmailVerificationStore::class,
            RememberTokenStoreInterface::class => DBLayerRememberTokenStore::class,
            RefreshTokenStoreInterface::class => DBLayerRefreshTokenStore::class,
            PasskeyCredentialStoreInterface::class => DBLayerPasskeyCredentialStore::class,
            RoleStoreInterface::class => DBLayerRoleStore::class,
            PermissionStoreInterface::class => DBLayerPermissionStore::class,
            GrantStoreInterface::class => DBLayerGrantStore::class,
            DeviceStoreInterface::class => DBLayerDeviceStore::class,
            LockoutStoreInterface::class => DBLayerLockoutStore::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    private function clockedMemoryStores(): array
    {
        return [
            GrantStoreInterface::class => InMemoryGrantStore::class,
            LockoutStoreInterface::class => InMemoryLockoutStore::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    private function plainDbStores(): array
    {
        return [
            AccountStoreInterface::class => DBLayerAccountStore::class,
            SessionStoreInterface::class => DBLayerSessionStore::class,
            MfaFactorStoreInterface::class => DBLayerMfaFactorStore::class,
            AuditEventStoreInterface::class => DBLayerAuditEventStore::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    private function plainMemoryStores(): array
    {
        return [
            AccountStoreInterface::class => InMemoryAccountStore::class,
            SessionStoreInterface::class => InMemorySessionStore::class,
            PasswordResetStoreInterface::class => InMemoryPasswordResetStore::class,
            EmailVerificationStoreInterface::class => InMemoryEmailVerificationStore::class,
            RememberTokenStoreInterface::class => InMemoryRememberTokenStore::class,
            RefreshTokenStoreInterface::class => InMemoryRefreshTokenStore::class,
            MfaFactorStoreInterface::class => InMemoryMfaFactorStore::class,
            PasskeyCredentialStoreInterface::class => InMemoryPasskeyCredentialStore::class,
            RoleStoreInterface::class => InMemoryRoleStore::class,
            PermissionStoreInterface::class => InMemoryPermissionStore::class,
            DeviceStoreInterface::class => InMemoryDeviceStore::class,
            AuditEventStoreInterface::class => InMemoryAuditEventStore::class,
        ];
    }

    private function registerDBLayerStores(): void
    {
        $connection = $this->authConnection();

        foreach ($this->plainDbStores() as $id => $storeClass) {
            $this->bindPlainDbStore($id, $storeClass, $connection);
        }

        foreach ($this->clockedDbStores() as $id => $storeClass) {
            $this->bindClockedDbStore($id, $storeClass, $connection);
        }

        $this->registerStoreAliases();
    }

    private function registerMemoryStores(): void
    {
        foreach ($this->plainMemoryStores() as $id => $storeClass) {
            $this->singleton($id, fn() => new $storeClass());
        }

        foreach ($this->clockedMemoryStores() as $id => $storeClass) {
            $this->singleton($id, fn() => new $storeClass(
                $this->service(ClockInterface::class),
            ));
        }

        $this->registerStoreAliases();
    }

    private function registerStoreAliases(): void
    {
        $this->alias(AccountProviderInterface::class, AccountStoreInterface::class);
        $this->alias(RoleAssignmentStoreInterface::class, RoleStoreInterface::class);
        $this->alias(PermissionAssignmentStoreInterface::class, PermissionStoreInterface::class);
    }
}
