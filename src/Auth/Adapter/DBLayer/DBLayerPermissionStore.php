<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authorization\Permission\Permission;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionAssignmentStoreInterface;
use Infocyph\Foundation\Auth\Authorization\Permission\PermissionStoreInterface;

final readonly class DBLayerPermissionStore extends ClockedDBLayerStore implements PermissionAssignmentStoreInterface, PermissionStoreInterface
{
    public function assignPermissionToAccount(string $accountId, string $permissionId): void
    {
        if ($this->exists(
            sprintf('SELECT account_id FROM %s WHERE account_id = ? AND permission_id = ?', $this->table('accountPermissions')),
            [$accountId, $permissionId],
        )) {
            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (account_id, permission_id, created_at) VALUES (?, ?, ?)', $this->table('accountPermissions')),
            [$accountId, $permissionId, $this->now()],
        );
    }

    public function assignPermissionToRole(string $roleId, string $permissionId): void
    {
        if ($this->exists(
            sprintf('SELECT role_id FROM %s WHERE role_id = ? AND permission_id = ?', $this->table('rolePermissions')),
            [$roleId, $permissionId],
        )) {
            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (role_id, permission_id, created_at) VALUES (?, ?, ?)', $this->table('rolePermissions')),
            [$roleId, $permissionId, $this->now()],
        );
    }

    public function permissionsForAccount(string $accountId): array
    {
        return $this->allMapped(
            sprintf('SELECT p.* FROM %s p INNER JOIN %s ap ON ap.permission_id = p.id WHERE ap.account_id = ?', $this->table('permissions'), $this->table('accountPermissions')),
            $this->mapPermission(...),
            [$accountId],
        );
    }

    public function permissionsForRoles(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));

        return $this->allMapped(
            sprintf('SELECT DISTINCT p.* FROM %s p INNER JOIN %s rp ON rp.permission_id = p.id WHERE rp.role_id IN (%s)', $this->table('permissions'), $this->table('rolePermissions'), $placeholders),
            $this->mapPermission(...),
            $roleIds,
        );
    }

    public function revokePermissionFromAccount(string $accountId, string $permissionId): void
    {
        $this->execute(
            sprintf('DELETE FROM %s WHERE account_id = ? AND permission_id = ?', $this->table('accountPermissions')),
            [$accountId, $permissionId],
        );
    }

    public function revokePermissionFromRole(string $roleId, string $permissionId): void
    {
        $this->execute(
            sprintf('DELETE FROM %s WHERE role_id = ? AND permission_id = ?', $this->table('rolePermissions')),
            [$roleId, $permissionId],
        );
    }

    public function save(Permission $permission): void
    {
        $this->upsertRecord('permissions', 'id', [
            'id' => $permission->id,
            'name' => $permission->name,
            'metadata' => DBLayerJson::encode($permission->metadata),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapPermission(array $row): Permission
    {
        return new Permission(
            id: $this->string($row['id'] ?? ''),
            name: $this->string($row['name'] ?? ''),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
