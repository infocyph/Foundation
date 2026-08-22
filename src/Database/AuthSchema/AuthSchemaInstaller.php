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
        private AuthMfaRevisionSchema $mfaRevisionSchema,
        private AuthTables $tables,
    ) {}

    public function install(?string $connection = null): void
    {
        $this->runner($connection)->run();
    }

    public function installed(?string $connection = null): bool
    {
        return $this->readiness($connection)['installed'];
    }

    /**
     * @return array{
     *   installed: bool,
     *   installed_tables: list<string>,
     *   missing_tables: list<string>,
     *   missing_columns: list<string>
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

        $missingColumns = [];
        $mfaFactors = $this->tables->mfaFactors();
        if ($schema->hasTable($mfaFactors) && !$schema->hasColumn($mfaFactors, 'revision')) {
            $missingColumns[] = $mfaFactors . '.revision';
        }

        return [
            'installed' => $missing === [] && $missingColumns === [],
            'installed_tables' => $installed,
            'missing_tables' => $missing,
            'missing_columns' => $missingColumns,
        ];
    }

    public function runner(?string $connection = null): MigrationRunner
    {
        return new MigrationRunner(
            $this->factory->connection($connection),
            [$this->schema, $this->mfaRevisionSchema],
        );
    }

    public function uninstall(?string $connection = null): void
    {
        $this->runner($connection)->reset(true);
    }
}
