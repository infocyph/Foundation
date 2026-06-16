<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Role;

interface RoleStoreInterface
{
    /**
     * @return list<Role>
     */
    public function rolesForAccount(string $accountId): array;
}
