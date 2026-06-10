<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\AuthLayer\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\AuthLayer\Authorization\Gate\AuthorizerInterface;
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
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\AuthLayer\Contract\Storage\AuditEventStoreInterface;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthAuthorizationRegistrar
{
    public function __construct(
        private Container $container,
    ) {}

    public function register(): void
    {
        $container = $this->container;

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
        $container->bind(AuthorizerInterface::class, fn() => $container->get(PermissionAuthorizer::class), LifetimeEnum::Singleton);
    }
}
