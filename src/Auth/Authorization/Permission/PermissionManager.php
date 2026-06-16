<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Permission;

use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;

final readonly class PermissionManager
{
    public function __construct(
        private PermissionStoreInterface $permissions,
        private PermissionAssignmentStoreInterface $assignments,
        private AuthIdGeneratorInterface $ids,
    ) {}

    public function assignToAccount(string $accountId, string $permissionId): void
    {
        $this->assignments->assignPermissionToAccount($accountId, $permissionId);
    }

    public function assignToRole(string $roleId, string $permissionId): void
    {
        $this->assignments->assignPermissionToRole($roleId, $permissionId);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function create(string $name, array $metadata = []): Permission
    {
        $permission = new Permission(
            id: $this->ids->permissionId(),
            name: $name,
            metadata: $metadata,
        );
        $this->assignments->save($permission);

        return $permission;
    }

    /**
     * @return list<Permission>
     */
    public function forAccount(string $accountId): array
    {
        return $this->permissions->permissionsForAccount($accountId);
    }

    /**
     * @param list<string> $roleIds
     * @return list<Permission>
     */
    public function forRoles(array $roleIds): array
    {
        return $this->permissions->permissionsForRoles($roleIds);
    }

    public function revokeFromAccount(string $accountId, string $permissionId): void
    {
        $this->assignments->revokePermissionFromAccount($accountId, $permissionId);
    }

    public function revokeFromRole(string $roleId, string $permissionId): void
    {
        $this->assignments->revokePermissionFromRole($roleId, $permissionId);
    }
}
