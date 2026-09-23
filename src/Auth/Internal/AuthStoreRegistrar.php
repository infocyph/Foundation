<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Epicrypt\DataProtection\StringProtector;
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
use Infocyph\Foundation\Auth\Adapter\Epicrypt\MfaSecretProtector;
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
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthMfaDriver;
use Infocyph\Foundation\Auth\Driver\AuthStorageDriver;
use Infocyph\Foundation\Auth\Mfa\{MfaFactorCompareAndSwapStoreInterface, MfaFactorStoreInterface};
use Infocyph\Foundation\Auth\Passkey\{
    PasskeyCredentialCompareAndSwapStoreInterface,
    PasskeyCredentialStoreInterface
};
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
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class AuthStoreRegistrar extends AbstractAuthRegistrar
{
    private const string AUDIT_STORAGE = 'foundation.auth.audit.storage';

    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->storage() === AuthStorageDriver::DATABASE) {
            $this->requirePackage(Connection::class, 'infocyph/dblayer', 'db');
            $this->registerDBLayerStores($drivers->mfa() === AuthMfaDriver::OTP);

            return;
        }
        $this->registerMemoryStores();
    }

    private function authConnection(): ?string
    {
        $default = $this->app->config()->get('database.default');

        return is_string($default) && $default !== '' ? $default : null;
    }

    /** @param class-string $storeClass */
    private function bindClockedDbStore(string $id, string $storeClass, ?string $connection): void
    {
        $this->recipe($id, $storeClass, [
            $this->ref(DBLayerFactory::class),
            $this->ref(AuthTables::class),
            $this->ref(ClockInterface::class),
            $connection,
        ]);
    }

    private function bindMfaDbStore(?string $connection, bool $protectSecrets): void
    {
        $arguments = [
            $this->ref(DBLayerFactory::class),
            $this->ref(AuthTables::class),
            $connection,
        ];

        if ($protectSecrets) {
            $this->requirePackage(StringProtector::class, 'infocyph/epicrypt', 'crypto');
            $this->staticRecipe(
                MfaSecretProtector::class,
                AuthMfaGraphFactory::class,
                'secretProtector',
                [$this->ref(ConfigRepository::class)],
            );
            $arguments[] = $this->ref(MfaSecretProtector::class);
        }

        $this->recipe(
            MfaFactorCompareAndSwapStoreInterface::class,
            DBLayerMfaFactorStore::class,
            $arguments,
        );
    }

    /** @param class-string $storeClass */
    private function bindPlainDbStore(string $id, string $storeClass, ?string $connection): void
    {
        $this->recipe($id, $storeClass, [
            $this->ref(DBLayerFactory::class),
            $this->ref(AuthTables::class),
            $connection,
        ]);
    }

    /** @return array<string, class-string> */
    private function clockedDbStores(): array
    {
        return [
            PasswordResetStoreInterface::class => DBLayerPasswordResetStore::class,
            EmailVerificationStoreInterface::class => DBLayerEmailVerificationStore::class,
            RememberTokenStoreInterface::class => DBLayerRememberTokenStore::class,
            RefreshTokenStoreInterface::class => DBLayerRefreshTokenStore::class,
            PasskeyCredentialCompareAndSwapStoreInterface::class => DBLayerPasskeyCredentialStore::class,
            RoleStoreInterface::class => DBLayerRoleStore::class,
            PermissionStoreInterface::class => DBLayerPermissionStore::class,
            GrantStoreInterface::class => DBLayerGrantStore::class,
            DeviceStoreInterface::class => DBLayerDeviceStore::class,
            LockoutStoreInterface::class => DBLayerLockoutStore::class,
        ];
    }

    /** @return array<string, class-string> */
    private function clockedMemoryStores(): array
    {
        return [
            GrantStoreInterface::class => InMemoryGrantStore::class,
            LockoutStoreInterface::class => InMemoryLockoutStore::class,
        ];
    }

    /** @return array<string, class-string> */
    private function plainDbStores(): array
    {
        return [
            AccountStoreInterface::class => DBLayerAccountStore::class,
            SessionStoreInterface::class => DBLayerSessionStore::class,
            self::AUDIT_STORAGE => DBLayerAuditEventStore::class,
        ];
    }

    /** @return array<string, class-string> */
    private function plainMemoryStores(): array
    {
        return [
            AccountStoreInterface::class => InMemoryAccountStore::class,
            SessionStoreInterface::class => InMemorySessionStore::class,
            PasswordResetStoreInterface::class => InMemoryPasswordResetStore::class,
            EmailVerificationStoreInterface::class => InMemoryEmailVerificationStore::class,
            RememberTokenStoreInterface::class => InMemoryRememberTokenStore::class,
            RefreshTokenStoreInterface::class => InMemoryRefreshTokenStore::class,
            MfaFactorCompareAndSwapStoreInterface::class => InMemoryMfaFactorStore::class,
            PasskeyCredentialCompareAndSwapStoreInterface::class => InMemoryPasskeyCredentialStore::class,
            RoleStoreInterface::class => InMemoryRoleStore::class,
            PermissionStoreInterface::class => InMemoryPermissionStore::class,
            DeviceStoreInterface::class => InMemoryDeviceStore::class,
            self::AUDIT_STORAGE => InMemoryAuditEventStore::class,
        ];
    }

    private function registerDBLayerStores(bool $protectMfaSecrets): void
    {
        $connection = $this->authConnection();

        foreach ($this->plainDbStores() as $id => $storeClass) {
            $this->bindPlainDbStore($id, $storeClass, $connection);
        }
        $this->bindMfaDbStore($connection, $protectMfaSecrets);
        foreach ($this->clockedDbStores() as $id => $storeClass) {
            $this->bindClockedDbStore($id, $storeClass, $connection);
        }

        $this->registerStoreAliases();
    }

    private function registerMemoryStores(): void
    {
        foreach ($this->plainMemoryStores() as $id => $storeClass) {
            $this->recipe($id, $storeClass);
        }
        foreach ($this->clockedMemoryStores() as $id => $storeClass) {
            $this->recipe($id, $storeClass, [$this->ref(ClockInterface::class)]);
        }

        $this->registerStoreAliases();
    }

    private function registerStoreAliases(): void
    {
        if ($this->boolConfig('messaging.forward_auth_events', false)) {
            $this->staticRecipe(
                AuditEventStoreInterface::class,
                AuthStoreGraphFactory::class,
                'forwardingAuditStore',
                [
                    $this->ref(self::AUDIT_STORAGE),
                    $this->ref(EventDispatcherInterface::class),
                ],
            );
        } else {
            $this->alias(AuditEventStoreInterface::class, self::AUDIT_STORAGE);
        }

        $this->alias(AccountProviderInterface::class, AccountStoreInterface::class);
        $this->alias(MfaFactorStoreInterface::class, MfaFactorCompareAndSwapStoreInterface::class);
        $this->alias(PasskeyCredentialStoreInterface::class, PasskeyCredentialCompareAndSwapStoreInterface::class);
        $this->alias(RoleAssignmentStoreInterface::class, RoleStoreInterface::class);
        $this->alias(PermissionAssignmentStoreInterface::class, PermissionStoreInterface::class);
    }
}
