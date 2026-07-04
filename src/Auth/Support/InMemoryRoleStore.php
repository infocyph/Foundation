<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authorization\Role\Role;
use Infocyph\Foundation\Auth\Authorization\Role\RoleAssignmentStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleStoreInterface;

final class InMemoryRoleStore implements RoleAssignmentStoreInterface, RoleStoreInterface
{
    /**
     * @var array<string, list<string>>
     */
    private array $accountRoles = [];

    /**
     * @var array<string, Role>
     */
    private array $roles = [];

    public function assignRole(string $accountId, string $roleId): void
    {
        $this->accountRoles[$accountId] ??= [];

        if (!in_array($roleId, $this->accountRoles[$accountId], true)) {
            $this->accountRoles[$accountId][] = $roleId;
        }
    }

    public function revokeRole(string $accountId, string $roleId): void
    {
        $this->accountRoles[$accountId] = array_values(array_filter(
            $this->accountRoles[$accountId] ?? [],
            static fn(string $assignedRoleId): bool => $assignedRoleId !== $roleId,
        ));
    }

    public function rolesForAccount(string $accountId): array
    {
        $roles = [];

        foreach ($this->accountRoles[$accountId] ?? [] as $roleId) {
            if (isset($this->roles[$roleId])) {
                $roles[] = $this->roles[$roleId];
            }
        }

        return $roles;
    }

    public function save(Role $role): void
    {
        $this->roles[$role->id] = $role;
    }
}
