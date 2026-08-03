<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Application\Application;

final readonly class DatabaseInspector
{
    public function __construct(private Application $application) {}

    /** @return array{connection:string,driver:string,database:string,version:string,connected:bool,table_prefix:string,table_count:int,tables:list<string>} */
    public function database(?string $name = null): array
    {
        $database = $this->application->db();
        $connection = $database->connection($name);
        $tables = new SchemaManager($connection)->tables();
        $resolved = $name ?? $database->config('default');
        if (!is_string($resolved) || $resolved === '') {
            throw new \RuntimeException('database.default must contain a connection name.');
        }

        return [
            'connection' => $resolved,
            'driver' => $connection->getDriverName(),
            'database' => $connection->getDatabaseName(),
            'version' => $database->version($name),
            'connected' => $database->ping($name),
            'table_prefix' => $connection->getTablePrefix(),
            'table_count' => count($tables),
            'tables' => $tables,
        ];
    }

    /**
     * @return array{
     *   connection:string|null,
     *   driver:string,
     *   table:string,
     *   columns:list<array<string,mixed>>,
     *   indexes:list<array<string,mixed>>,
     *   foreign_keys:list<array<string,mixed>>
     * }
     */
    public function table(string $table, ?string $name = null): array
    {
        Blueprint::assertIdentifier($table);
        $connection = $this->application->db()->connection($name);
        $columns = $this->columns($connection, $table);
        if ($columns === []) {
            throw new \InvalidArgumentException(sprintf('Database table "%s" does not exist.', $table));
        }

        return [
            'connection' => $name,
            'driver' => $connection->getDriverName(),
            'table' => $table,
            'columns' => $columns,
            'indexes' => $this->indexes($connection, $table),
            'foreign_keys' => $this->foreignKeys($connection, $table),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function columns(Connection $connection, string $table): array
    {
        [$schema, $table] = $this->parts($connection, $table);

        return match ($connection->getDriverName()) {
            'mysql' => array_values($connection->select(
                'SELECT column_name AS name, column_type AS type, is_nullable AS nullable, '
                . 'column_default AS `default`, column_key AS `key`, extra, ordinal_position AS position '
                . 'FROM information_schema.columns WHERE table_schema = ? AND table_name = ? '
                . 'ORDER BY ordinal_position',
                [$schema, $table],
            )),
            'pgsql' => array_values($connection->select(
                'SELECT column_name AS name, data_type AS type, is_nullable AS nullable, '
                . 'column_default AS "default", ordinal_position AS position '
                . 'FROM information_schema.columns WHERE table_schema = ? AND table_name = ? '
                . 'ORDER BY ordinal_position',
                [$schema, $table],
            )),
            default => array_values($connection->select(sprintf('PRAGMA table_info(%s)', $this->quote($table)))),
        };
    }

    /** @return list<array<string,mixed>> */
    private function foreignKeys(Connection $connection, string $table): array
    {
        [$schema, $table] = $this->parts($connection, $table);

        return match ($connection->getDriverName()) {
            'mysql' => array_values($connection->select(
                'SELECT kcu.constraint_name AS name, kcu.column_name AS column_name, '
                . 'kcu.referenced_table_name AS referenced_table, '
                . 'kcu.referenced_column_name AS referenced_column, '
                . 'rc.update_rule AS on_update, rc.delete_rule AS on_delete '
                . 'FROM information_schema.key_column_usage kcu '
                . 'JOIN information_schema.referential_constraints rc '
                . 'ON rc.constraint_schema = kcu.constraint_schema '
                . 'AND rc.constraint_name = kcu.constraint_name '
                . 'WHERE kcu.table_schema = ? AND kcu.table_name = ? '
                . 'AND kcu.referenced_table_name IS NOT NULL ORDER BY kcu.constraint_name, kcu.ordinal_position',
                [$schema, $table],
            )),
            'pgsql' => array_values($connection->select(
                'SELECT tc.constraint_name AS name, kcu.column_name AS column_name, '
                . 'ccu.table_name AS referenced_table, ccu.column_name AS referenced_column, '
                . 'rc.update_rule AS on_update, rc.delete_rule AS on_delete '
                . 'FROM information_schema.table_constraints tc '
                . 'JOIN information_schema.key_column_usage kcu '
                . 'ON tc.constraint_name = kcu.constraint_name AND tc.constraint_schema = kcu.constraint_schema '
                . 'JOIN information_schema.constraint_column_usage ccu '
                . 'ON ccu.constraint_name = tc.constraint_name AND ccu.constraint_schema = tc.constraint_schema '
                . 'JOIN information_schema.referential_constraints rc '
                . 'ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.constraint_schema '
                . 'WHERE tc.constraint_type = ? AND tc.table_schema = ? AND tc.table_name = ? '
                . 'ORDER BY tc.constraint_name, kcu.ordinal_position',
                ['FOREIGN KEY', $schema, $table],
            )),
            default => array_values($connection->select(
                sprintf('PRAGMA foreign_key_list(%s)', $this->quote($table)),
            )),
        };
    }

    /** @return list<array<string,mixed>> */
    private function indexes(Connection $connection, string $table): array
    {
        [$schema, $table] = $this->parts($connection, $table);
        if ($connection->getDriverName() === 'mysql') {
            return array_values($connection->select(
                'SELECT index_name AS name, non_unique, seq_in_index AS position, '
                . 'column_name, index_type AS type FROM information_schema.statistics '
                . 'WHERE table_schema = ? AND table_name = ? ORDER BY index_name, seq_in_index',
                [$schema, $table],
            ));
        }
        if ($connection->getDriverName() === 'pgsql') {
            return array_values($connection->select(
                'SELECT indexname AS name, indexdef AS definition FROM pg_catalog.pg_indexes '
                . 'WHERE schemaname = ? AND tablename = ? ORDER BY indexname',
                [$schema, $table],
            ));
        }

        $indexes = [];
        foreach ($connection->select(sprintf('PRAGMA index_list(%s)', $this->quote($table))) as $index) {
            $name = $index['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $columns = [];
            foreach ($connection->select(sprintf('PRAGMA index_info(%s)', $this->quote($name))) as $column) {
                if (is_string($column['name'] ?? null)) {
                    $columns[] = $column['name'];
                }
            }
            $index['columns'] = $columns;
            $indexes[] = $index;
        }

        return $indexes;
    }

    /** @return array{0:string,1:string} */
    private function parts(Connection $connection, string $table): array
    {
        $parts = explode('.', $table, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        $schema = $connection->getDatabaseName();
        if ($connection->getDriverName() === 'pgsql') {
            $current = $connection->scalar('SELECT current_schema()');
            if (!is_string($current) || $current === '') {
                throw new \RuntimeException('PostgreSQL did not report a current schema.');
            }
            $schema = $current;
        }

        return [$schema, $connection->getTablePrefix() . $table];
    }

    private function quote(string $identifier): string
    {
        Blueprint::assertIdentifier($identifier);

        return '"' . $identifier . '"';
    }
}
