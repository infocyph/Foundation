<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Role;

use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;

final readonly class RoleManager
{
    public function __construct(
        private RoleStoreInterface $roles,
        private RoleAssignmentStoreInterface $assignments,
        private AuthIdGeneratorInterface $ids,
    ) {}

    public function assign(string $accountId, string $roleId): void
    {
        $this->assignments->assignRole($accountId, $roleId);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function create(string $name, array $metadata = []): Role
    {
        $role = new Role($this->ids->roleId(), $name, $metadata);
        $this->assignments->save($role);

        return $role;
    }

    /**
     * @return list<Role>
     */
    public function forAccount(string $accountId): array
    {
        return $this->roles->rolesForAccount($accountId);
    }

    public function revoke(string $accountId, string $roleId): void
    {
        $this->assignments->revokeRole($accountId, $roleId);
    }
}
