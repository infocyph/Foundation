<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Schema\SchemaManager;
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
        $this->runner($connection)->run();
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
        $schema = new SchemaManager($this->factory->connection($connection));
        $installed = [];
        $missing = [];

        foreach ($this->tables->all() as $table) {
            if ($schema->hasTable($table)) {
                $installed[] = $table;
            } else {
                $missing[] = $table;
            }
        }

        return [
            'installed' => $missing === [],
            'installed_tables' => $installed,
            'missing_tables' => $missing,
        ];
    }

    public function runner(?string $connection = null): MigrationRunner
    {
        return new MigrationRunner(
            $this->factory->connection($connection),
            [$this->schema],
        );
    }

    public function uninstall(?string $connection = null): void
    {
        $this->runner($connection)->reset(true);
    }
}
