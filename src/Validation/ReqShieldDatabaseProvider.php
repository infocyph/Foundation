<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\ReqShield\Contracts\DatabaseProvider;

final readonly class ReqShieldDatabaseProvider implements DatabaseProvider
{
    public function __construct(
        private DatabaseManager $database,
        private ?string $connection = null,
    ) {}

    /**
     * @param array<int|string, mixed> $checks
     * @return array<int, int|string>
     */
    public function batchExistsCheck(string $table, array $checks): array
    {
        /** @var array<string, list<array{identifier:int|string,value:mixed}>> $grouped */
        $grouped = [];

        foreach ($checks as $key => $check) {
            $column = is_array($check) ? $this->stringValue($check['column'] ?? null) : $this->stringValue($key);
            if ($column === '') {
                continue;
            }

            $value = is_array($check) ? ($check['value'] ?? null) : $check;
            $grouped[$column][] = [
                'identifier' => is_array($check)
                    ? $this->identifier($check['field'] ?? $value, $key)
                    : $this->identifier($value, $key),
                'value' => $value,
            ];
        }

        $missing = [];

        foreach ($grouped as $column => $entries) {
            $matched = $this->matchedEntries(
                $this->rowsForValues(
                    $this->database->query($this->connection)->from($table)->select($this->column($column)),
                    $this->column($column),
                    $this->entryValues($entries),
                ),
                $column,
                $entries,
            );

            foreach ($entries as $index => $entry) {
                if (isset($matched[$index])) {
                    continue;
                }

                $missing[] = $entry['identifier'];
            }
        }

        return $missing;
    }

    /**
     * @param array<int|string, mixed> $checks
     * @return array<int, int|string>
     */
    public function batchUniqueCheck(string $table, array $checks): array
    {
        /** @var array<string, array{checks:list<array{identifier:int|string,value:mixed}>,column:string,id_column:string,ignore_id:?int,soft_delete_column:string,with_trashed:bool}> $grouped */
        $grouped = [];

        foreach ($checks as $key => $check) {
            $this->addUniqueCheck($grouped, $key, $check);
        }

        $nonUnique = [];

        foreach ($grouped as $group) {
            $query = $this->database->query($this->connection)
                ->from($table)
                ->select($this->column($group['column']));

            if (!$group['with_trashed']) {
                $query->whereNull($group['soft_delete_column']);
            }

            if ($group['ignore_id'] !== null) {
                $query->where($this->column($group['id_column']), '!=', $group['ignore_id']);
            }

            $matched = $this->matchedEntries(
                $this->rowsForValues($query, $this->column($group['column']), $this->entryValues($group['checks'])),
                $group['column'],
                $group['checks'],
            );

            foreach ($group['checks'] as $index => $entry) {
                if (!isset($matched[$index])) {
                    continue;
                }

                $nonUnique[] = $entry['identifier'];
            }
        }

        return $nonUnique;
    }

    /** @param array<string, mixed> $columns */
    public function compositeUnique(string $table, array $columns, ?int $ignoreId = null): bool
    {
        $query = $this->database->query($this->connection)->from($table);

        foreach ($columns as $column => $value) {
            $column = $this->column($column);

            if ($value === null) {
                $query->whereNull($column);

                continue;
            }

            $query->where($column, $value);
        }

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return !$query->exists();
    }

    public function exists(string $table, string $column, mixed $value, ?int $ignoreId = null): bool
    {
        $column = $this->column($column);
        $query = $this->database->query($this->connection)->from($table);

        if ($value === null) {
            $query->whereNull($column);
        } else {
            $query->where($column, $value);
        }

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function query(string $query, array $params = []): array
    {
        return $this->database->select($query, $params, $this->connection);
    }

    /**
     * @param array<string, array{checks:list<array{identifier:int|string,value:mixed}>,column:string,id_column:string,ignore_id:?int,soft_delete_column:string,with_trashed:bool}> $grouped
     */
    private function addUniqueCheck(array &$grouped, int|string $key, mixed $check): void
    {
        [$column, $value, $identifier, $ignoreId, $idColumn, $withTrashed, $softDeleteColumn] = $this->uniqueCheck($key, $check);
        if ($column === '') {
            return;
        }

        $groupKey = serialize([$column, $idColumn, $ignoreId, $withTrashed, $softDeleteColumn]);
        $grouped[$groupKey] ??= [
            'checks' => [],
            'column' => $column,
            'id_column' => $idColumn,
            'ignore_id' => $ignoreId,
            'soft_delete_column' => $softDeleteColumn,
            'with_trashed' => $withTrashed,
        ];
        $grouped[$groupKey]['checks'][] = ['identifier' => $identifier, 'value' => $value];
    }

    /** @return non-empty-string */
    private function column(string $column): string
    {
        if ($column === '') {
            throw new \InvalidArgumentException('Database validation columns must be non-empty strings.');
        }

        return $column;
    }

    /**
     * @param list<array{identifier:int|string,value:mixed}> $entries
     * @return list<mixed>
     */
    private function entryValues(array $entries): array
    {
        return array_map(static fn(array $entry): mixed => $entry['value'], $entries);
    }

    private function identifier(mixed $value, int|string $fallback): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return $fallback;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param list<array{identifier:int|string,value:mixed}> $entries
     * @param list<array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function matchedEntries(array $rows, string $column, array $entries): array
    {
        $matched = [];

        foreach ($entries as $index => $entry) {
            foreach ($rows as $row) {
                if (!array_key_exists($column, $row) || !$this->sameDatabaseValue($row[$column], $entry['value'])) {
                    continue;
                }

                $matched[$index] = true;

                break;
            }
        }

        return $matched;
    }

    /**
     * @param list<mixed> $values
     * @return list<array<string, mixed>>
     */
    private function rowsForValues(QueryBuilder $query, string $column, array $values): array
    {
        $rows = [];
        $nonNullValues = array_values(array_filter($values, static fn(mixed $value): bool => $value !== null));

        if ($nonNullValues !== []) {
            $rows = $query->cloneBuilder()->whereIn($column, $nonNullValues)->get();
        }

        if (!in_array(null, $values, true)) {
            return $rows;
        }

        return [...$rows, ...$query->cloneBuilder()->whereNull($column)->get()];
    }

    private function sameDatabaseValue(mixed $actual, mixed $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        return is_scalar($actual) && is_scalar($expected) && (string) $actual === (string) $expected;
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @return array{0:string,1:mixed,2:int|string,3:?int,4:string,5:bool,6:string}
     */
    private function uniqueCheck(int|string $key, mixed $check): array
    {
        if (!is_array($check)) {
            return [$this->stringValue($key), $check, $this->identifier($check, $key), null, 'id', true, 'deleted_at'];
        }

        $value = $check['value'] ?? null;

        return [
            $this->stringValue($check['column'] ?? null),
            $value,
            $this->identifier($check['field'] ?? $value, $key),
            $this->intValue($check['ignore_id'] ?? null),
            $this->stringValue($check['id_column'] ?? 'id') ?: 'id',
            $check['with_trashed'] === true,
            $this->stringValue($check['soft_delete_column'] ?? 'deleted_at') ?: 'deleted_at',
        ];
    }
}
