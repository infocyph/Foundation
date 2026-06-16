<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Permission;

interface PermissionAssignmentStoreInterface
{
    public function assignPermissionToAccount(string $accountId, string $permissionId): void;

    public function assignPermissionToRole(string $roleId, string $permissionId): void;

    public function revokePermissionFromAccount(string $accountId, string $permissionId): void;

    public function revokePermissionFromRole(string $roleId, string $permissionId): void;

    public function save(Permission $permission): void;
}
