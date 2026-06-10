<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class AuthSchemaInstaller
{
    public function __construct(
        private DBLayerFactory $factory,
        private AuthSchema $schema,
        private AuthTables $tables,
    ) {}

    public function install(?string $connection = null): void
    {
        $db = $this->factory->connection($connection);

        foreach ($this->schema->statements() as $statement) {
            $db->statement($statement);
        }
    }

    public function installed(?string $connection = null): bool
    {
        return $this->readiness($connection)['missing_tables'] === [];
    }

    /**
     * @return array{
     *   installed: bool,
     *   installed_tables: list<string>,
     *   missing_tables: list<string>
     * }
     */
    public function readiness(?string $connection = null): array
    {
        $db = $this->factory->connection($connection);
        $installed = [];
        $missing = [];

        foreach ($this->tables->all() as $table) {
            try {
                $db->select(sprintf('SELECT 1 FROM %s WHERE 1 = 0', $table));
                $installed[] = $table;
            } catch (\Throwable) {
                $missing[] = $table;
            }
        }

        return [
            'installed' => $missing === [],
            'installed_tables' => $installed,
            'missing_tables' => $missing,
        ];
    }

    public function uninstall(?string $connection = null): void
    {
        $db = $this->factory->connection($connection);

        foreach ($this->schema->dropStatements() as $statement) {
            $db->statement($statement);
        }
    }
}
