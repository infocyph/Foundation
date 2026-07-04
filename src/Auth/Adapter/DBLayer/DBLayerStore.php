<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

abstract readonly class DBLayerStore
{
    public function __construct(
        protected DBLayerFactory $db,
        protected AuthTables $tables,
        protected ?string $connection = null,
    ) {}

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    protected function all(string $sql, array $bindings = []): array
    {
        return array_values($this->connection()->select($sql, $bindings));
    }

    /**
     * @template TResult
     * @param callable(array<string, mixed>): TResult $mapper
     * @param list<mixed> $bindings
     * @return list<TResult>
     */
    protected function allMapped(string $sql, callable $mapper, array $bindings = []): array
    {
        return array_map($mapper, $this->all($sql, $bindings));
    }

    protected function connection(): Connection
    {
        return $this->db->connection($this->connection);
    }

    /**
     * @param list<mixed> $bindings
     */
    protected function deleteWhere(string $table, string $where, array $bindings = []): void
    {
        $this->execute(
            sprintf('DELETE FROM %s WHERE %s', $this->table($table), $where),
            $bindings,
        );
    }

    /**
     * @param list<mixed> $bindings
     */
    protected function execute(string $sql, array $bindings = []): void
    {
        $this->connection()->statement($sql, $bindings);
    }

    /**
     * @param list<mixed> $bindings
     */
    protected function exists(string $sql, array $bindings = []): bool
    {
        return $this->first($sql, $bindings) !== null;
    }

    /**
     * @param list<mixed> $bindings
     * @return array<string, mixed>|null
     */
    protected function first(string $sql, array $bindings = []): ?array
    {
        return $this->all($sql, $bindings)[0] ?? null;
    }

    /**
     * @template TResult
     * @param callable(array<string, mixed>): TResult $mapper
     * @param list<mixed> $bindings
     * @return TResult|null
     */
    protected function firstMapped(string $sql, callable $mapper, array $bindings = []): mixed
    {
        $row = $this->first($sql, $bindings);

        return $row === null ? null : $mapper($row);
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function insertRecord(string $table, array $record): void
    {
        $columns = array_keys($record);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $this->execute(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->table($table),
                implode(', ', $columns),
                $placeholders,
            ),
            array_values($record),
        );
    }

    protected function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function table(string $name): string
    {
        if (!method_exists($this->tables, $name)) {
            throw new \RuntimeException(sprintf('Unknown auth table "%s".', $name));
        }

        $table = $this->tables->{$name}();

        if (!is_string($table) || $table === '') {
            throw new \RuntimeException(sprintf('Auth table "%s" resolved to an invalid name.', $name));
        }

        return $table;
    }

    protected function truthy(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_string($value) => in_array(strtolower($value), ['1', 'true', 'yes'], true),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $values
     * @param list<mixed> $bindings
     */
    protected function updateWhere(string $table, array $values, string $where, array $bindings = []): void
    {
        $assignments = implode(', ', array_map(
            static fn(string $column): string => sprintf('%s = ?', $column),
            array_keys($values),
        ));

        $this->execute(
            sprintf('UPDATE %s SET %s WHERE %s', $this->table($table), $assignments, $where),
            [...array_values($values), ...$bindings],
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function upsertRecord(string $table, string $keyColumn, array $record): void
    {
        if (!array_key_exists($keyColumn, $record)) {
            throw new \InvalidArgumentException(sprintf(
                'Record for table "%s" must include key column "%s".',
                $table,
                $keyColumn,
            ));
        }

        $tableName = $this->table($table);
        $keyValue = $record[$keyColumn];
        $columns = $record;
        unset($columns[$keyColumn]);

        if ($this->exists(sprintf('SELECT %s FROM %s WHERE %s = ?', $keyColumn, $tableName, $keyColumn), [$keyValue])) {
            $assignments = implode(', ', array_map(
                static fn(string $column): string => sprintf('%s = ?', $column),
                array_keys($columns),
            ));

            $this->execute(
                sprintf('UPDATE %s SET %s WHERE %s = ?', $tableName, $assignments, $keyColumn),
                [...array_values($columns), $keyValue],
            );

            return;
        }

        $this->insertRecord($table, $record);
    }
}
