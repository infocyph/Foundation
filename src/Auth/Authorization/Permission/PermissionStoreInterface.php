<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Permission;

interface PermissionStoreInterface
{
    /**
     * @return list<Permission>
     */
    public function permissionsForAccount(string $accountId): array;

    /**
     * @param list<string> $roleIds
     * @return list<Permission>
     */
    public function permissionsForRoles(array $roleIds): array;
}
