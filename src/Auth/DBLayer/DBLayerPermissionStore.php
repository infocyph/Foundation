<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

use Infocyph\AuthLayer\Authorization\Permission\Permission;
use Infocyph\AuthLayer\Authorization\Permission\PermissionAssignmentStoreInterface;
use Infocyph\AuthLayer\Authorization\Permission\PermissionStoreInterface;
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerPermissionStore extends DBLayerStore implements PermissionStoreInterface, PermissionAssignmentStoreInterface
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        private ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

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
            [$accountId, $permissionId, $this->clock->now()],
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
            [$roleId, $permissionId, $this->clock->now()],
        );
    }

    public function permissionsForAccount(string $accountId): array
    {
        return array_map(
            fn(array $row): Permission => $this->mapPermission($row),
            $this->all(
                sprintf('SELECT p.* FROM %s p INNER JOIN %s ap ON ap.permission_id = p.id WHERE ap.account_id = ?', $this->table('permissions'), $this->table('accountPermissions')),
                [$accountId],
            ),
        );
    }

    public function permissionsForRoles(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));
        $rows = $this->all(
            sprintf('SELECT DISTINCT p.* FROM %s p INNER JOIN %s rp ON rp.permission_id = p.id WHERE rp.role_id IN (%s)', $this->table('permissions'), $this->table('rolePermissions'), $placeholders),
            array_values($roleIds),
        );

        return array_map(fn(array $row): Permission => $this->mapPermission($row), $rows);
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
        if ($this->first(
            sprintf('SELECT id FROM %s WHERE id = ?', $this->table('permissions')),
            [$permission->id],
        ) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET name = ?, metadata = ? WHERE id = ?', $this->table('permissions')),
                [$permission->name, DBLayerJson::encode($permission->metadata), $permission->id],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, name, metadata) VALUES (?, ?, ?)', $this->table('permissions')),
            [$permission->id, $permission->name, DBLayerJson::encode($permission->metadata)],
        );
    }

    private function mapPermission(array $row): Permission
    {
        return new Permission(
            id: $this->string($row['id'] ?? ''),
            name: $this->string($row['name'] ?? ''),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
