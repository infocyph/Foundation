<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Authorization\Gate\AbilityMatcher;
use Infocyph\Foundation\Auth\Authorization\Gate\AuditingAuthorizer;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Authorization\Gate\Gate;
use Infocyph\Foundation\Auth\Authorization\Gate\PermissionAuthorizer;
use Infocyph\Foundation\Auth\Authorization\Grant\DelegationManager;
use Infocyph\Foundation\Auth\Authorization\Grant\GrantResolver;
use Infocyph\Foundation\Auth\Authorization\Grant\GrantStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionAssignmentStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionManager;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionResolver;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Policy\PolicyResolverInterface;
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
        $this->recipe(RoleManager::class, RoleManager::class, [
            $this->ref(RoleStoreInterface::class),
            $this->ref(RoleAssignmentStoreInterface::class),
            $this->ref(AuthIdGeneratorInterface::class),
        ]);
        $this->recipe(PermissionManager::class, PermissionManager::class, [
            $this->ref(PermissionStoreInterface::class),
            $this->ref(PermissionAssignmentStoreInterface::class),
            $this->ref(AuthIdGeneratorInterface::class),
        ]);
        $this->recipe(DelegationManager::class, DelegationManager::class, [
            $this->ref(GrantStoreInterface::class),
            $this->ref(AuditEventStoreInterface::class),
            $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(PermissionResolver::class, PermissionResolver::class, [
            $this->ref(PermissionStoreInterface::class),
        ]);
        $this->recipe(RolePermissionResolver::class, RolePermissionResolver::class, [
            $this->ref(RoleStoreInterface::class),
            $this->ref(PermissionStoreInterface::class),
        ]);
        $this->recipe(AbilityMatcher::class, AbilityMatcher::class);
        $this->recipe(GrantResolver::class, GrantResolver::class, [
            $this->ref(GrantStoreInterface::class),
            $this->ref(AbilityMatcher::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(PermissionAuthorizer::class, PermissionAuthorizer::class, [
            $this->ref(PermissionResolver::class),
            $this->ref(RolePermissionResolver::class),
            $this->ref(GrantResolver::class),
        ]);
        $this->recipe(Gate::class, Gate::class, [
            $this->hasExplicitBinding(PolicyResolverInterface::class)
                ? $this->ref(PolicyResolverInterface::class)
                : null,
            $this->ref(PermissionAuthorizer::class),
        ]);
        $this->recipe(AuditingAuthorizer::class, AuditingAuthorizer::class, [
            $this->ref(Gate::class),
            $this->ref(AuditEventStoreInterface::class),
            $this->ref(AuthIdGeneratorInterface::class),
            $this->ref(ClockInterface::class),
        ]);
        $this->alias(AuthorizerInterface::class, AuditingAuthorizer::class);
    }
}
