<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Authorization\Gate\PermissionAuthorizer;
use Infocyph\Foundation\Auth\Authorization\Grant\DelegationManager;
use Infocyph\Foundation\Auth\Authorization\Grant\GrantResolver;
use Infocyph\Foundation\Auth\Authorization\Grant\GrantStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionAssignmentStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionManager;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionResolver;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleAssignmentStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\Authorization\Role\RolePermissionResolver;
use Infocyph\Foundation\Auth\Authorization\Role\RoleStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;

final readonly class AuthAuthorizationRegistrar extends AbstractAuthRegistrar
{
    public function register(): void
    {
        $this->singleton(RoleManager::class, fn() => new RoleManager(
            roles: $this->app->make(RoleStoreInterface::class),
            assignments: $this->app->make(RoleAssignmentStoreInterface::class),
            ids: $this->app->make(AuthIdGeneratorInterface::class),
        ));

        $this->singleton(PermissionManager::class, fn() => new PermissionManager(
            permissions: $this->app->make(PermissionStoreInterface::class),
            assignments: $this->app->make(PermissionAssignmentStoreInterface::class),
            ids: $this->app->make(AuthIdGeneratorInterface::class),
        ));

        $this->singleton(DelegationManager::class, fn() => new DelegationManager(
            grants: $this->app->make(GrantStoreInterface::class),
            audit: $this->app->make(AuditEventStoreInterface::class),
            ids: $this->app->make(AuthIdGeneratorInterface::class),
            clock: $this->app->make(ClockInterface::class),
        ));

        $this->singleton(PermissionResolver::class, fn() => new PermissionResolver(
            permissions: $this->app->make(PermissionStoreInterface::class),
        ));

        $this->singleton(RolePermissionResolver::class, fn() => new RolePermissionResolver(
            roles: $this->app->make(RoleStoreInterface::class),
            permissions: $this->app->make(PermissionStoreInterface::class),
        ));

        $this->singleton(GrantResolver::class, fn() => new GrantResolver(
            grants: $this->app->make(GrantStoreInterface::class),
            clock: $this->app->make(ClockInterface::class),
        ));

        $this->singleton(PermissionAuthorizer::class, fn() => new PermissionAuthorizer(
            permissions: $this->app->make(PermissionResolver::class),
            rolePermissions: $this->app->make(RolePermissionResolver::class),
            grants: $this->app->make(GrantResolver::class),
        ));
        $this->alias(AuthorizerInterface::class, PermissionAuthorizer::class);
    }
}
