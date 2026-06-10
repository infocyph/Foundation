<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

use Infocyph\AuthLayer\Authorization\Role\Role;
use Infocyph\AuthLayer\Authorization\Role\RoleAssignmentStoreInterface;
use Infocyph\AuthLayer\Authorization\Role\RoleStoreInterface;
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerRoleStore extends DBLayerStore implements RoleStoreInterface, RoleAssignmentStoreInterface
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        private ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

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
            [$accountId, $roleId, $this->clock->now()],
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
        return array_map(
            fn(array $row): Role => new Role(
                id: $this->string($row['id'] ?? ''),
                name: $this->string($row['name'] ?? ''),
                metadata: DBLayerJson::decode($row['metadata'] ?? null),
            ),
            $this->all(
                sprintf('SELECT r.* FROM %s r INNER JOIN %s ar ON ar.role_id = r.id WHERE ar.account_id = ?', $this->table('roles'), $this->table('accountRoles')),
                [$accountId],
            ),
        );
    }

    public function save(Role $role): void
    {
        if ($this->first(
            sprintf('SELECT id FROM %s WHERE id = ?', $this->table('roles')),
            [$role->id],
        ) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET name = ?, metadata = ? WHERE id = ?', $this->table('roles')),
                [$role->name, DBLayerJson::encode($role->metadata), $role->id],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, name, metadata) VALUES (?, ?, ?)', $this->table('roles')),
            [$role->id, $role->name, DBLayerJson::encode($role->metadata)],
        );
    }
}
