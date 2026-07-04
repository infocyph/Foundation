<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authorization\Role\Role;
use Infocyph\Foundation\Auth\Authorization\Role\RoleAssignmentStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleStoreInterface;

final readonly class DBLayerRoleStore extends ClockedDBLayerStore implements RoleAssignmentStoreInterface, RoleStoreInterface
{
    public function assignRole(string $accountId, string $roleId): void
    {
        if ($this->exists(
            sprintf('SELECT account_id FROM %s WHERE account_id = ? AND role_id = ?', $this->table('accountRoles')),
            [$accountId, $roleId],
        )) {
            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (account_id, role_id, created_at) VALUES (?, ?, ?)', $this->table('accountRoles')),
            [$accountId, $roleId, $this->now()],
        );
    }

    public function revokeRole(string $accountId, string $roleId): void
    {
        $this->execute(
            sprintf('DELETE FROM %s WHERE account_id = ? AND role_id = ?', $this->table('accountRoles')),
            [$accountId, $roleId],
        );
    }

    public function rolesForAccount(string $accountId): array
    {
        return $this->allMapped(
            sprintf('SELECT r.* FROM %s r INNER JOIN %s ar ON ar.role_id = r.id WHERE ar.account_id = ?', $this->table('roles'), $this->table('accountRoles')),
            $this->mapRole(...),
            [$accountId],
        );
    }

    public function save(Role $role): void
    {
        $this->upsertRecord('roles', 'id', [
            'id' => $role->id,
            'name' => $role->name,
            'metadata' => DBLayerJson::encode($role->metadata),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRole(array $row): Role
    {
        return new Role(
            id: $this->string($row['id'] ?? ''),
            name: $this->string($row['name'] ?? ''),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
