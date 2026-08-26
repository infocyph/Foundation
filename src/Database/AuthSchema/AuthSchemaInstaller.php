<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

use Infocyph\DBLayer\Migration\Migration;
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
        private ?AuthOAuthRevisionSchema $oauthRevisionSchema = null,
        private bool $oauthEnabled = false,
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

        $requiredTables = $this->tables->all();
        if ($this->oauthEnabled) {
            array_push($requiredTables, ...$this->tables->oauth());
        }

        foreach ($requiredTables as $table) {
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
        $migrations = [$this->schema, $this->mfaRevisionSchema];
        if ($this->oauthEnabled) {
            $migrations[] = $this->oauthSchema();
        }

        return new MigrationRunner(
            $this->factory->connection($connection),
            $migrations,
        );
    }

    public function uninstall(?string $connection = null): void
    {
        $this->runner($connection)->reset(true);
    }

    private function oauthSchema(): Migration
    {
        if (!$this->oauthRevisionSchema instanceof AuthOAuthRevisionSchema) {
            throw new \LogicException('OAuth auth schema is enabled but its revision migration is unavailable.');
        }

        return $this->oauthRevisionSchema;
    }
}
