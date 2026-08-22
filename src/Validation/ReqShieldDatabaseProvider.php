<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Closure;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\ReqShield\Contracts\DatabaseProvider;

/**
 * Adapts ReqShield's batched database rules to the configured DBLayer connection.
 */
final readonly class ReqShieldDatabaseProvider implements DatabaseProvider
{
    public function __construct(
        /** @var Closure():Connection */
        private Closure $connection,
    ) {}

    /**
     * @param list<array<string, mixed>> $checks
     * @return list<int|string>
     */
    public function batchExists(string $table, array $checks): array
    {
        /** @var array<string, list<array{identifier:int|string,value:mixed}>> $grouped */
        $grouped = [];
        foreach ($checks as $key => $check) {
            $column = $this->stringValue($check['column'] ?? null);
            if ($column === '') {
                continue;
            }
            $value = $check['value'] ?? null;
            $grouped[$column][] = [
                'identifier' => $this->identifier($check['id'] ?? $check['field'] ?? $value, $key),
                'value' => $value,
            ];
        }

        $missing = [];
        foreach ($grouped as $column => $entries) {
            $matched = $this->matchedEntries(
                $this->rowsForValues(
                    $this->query($table)->select($this->column($column)),
                    $this->column($column),
                    $this->entryValues($entries),
                ),
                $column,
                $entries,
            );

            foreach ($entries as $index => $entry) {
                if (!isset($matched[$index])) {
                    $missing[] = $entry['identifier'];
                }
            }
        }

        return $missing;
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @return list<int|string>
     */
    public function batchUnique(string $table, array $checks): array
    {
        /** @var array<string, array{checks:list<array{identifier:int|string,value:mixed}>,column:string,id_column:string,ignore_id:int|string|null,soft_delete_column:string,with_trashed:bool}> $grouped */
        $grouped = [];
        foreach ($checks as $key => $check) {
            $this->addUniqueCheck($grouped, $key, $check);
        }

        $nonUnique = [];
        foreach ($grouped as $group) {
            $query = $this->query($table)->select($this->column($group['column']));
            if (!$group['with_trashed']) {
                $query->whereNull($this->column($group['soft_delete_column']));
            }
            if ($group['ignore_id'] !== null) {
                $query->where($this->column($group['id_column']), '!=', $group['ignore_id']);
            }

            $matched = $this->matchedEntries(
                $this->rowsForValues(
                    $query,
                    $this->column($group['column']),
                    $this->entryValues($group['checks']),
                ),
                $group['column'],
                $group['checks'],
            );

            foreach ($group['checks'] as $index => $entry) {
                if (isset($matched[$index])) {
                    $nonUnique[] = $entry['identifier'];
                }
            }
        }

        return $nonUnique;
    }

    /**
     * @param array<string, array{checks:list<array{identifier:int|string,value:mixed}>,column:string,id_column:string,ignore_id:int|string|null,soft_delete_column:string,with_trashed:bool}> $grouped
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

    /** @param list<array{identifier:int|string,value:mixed}> $entries @return list<mixed> */
    private function entryValues(array $entries): array
    {
        return array_map(static fn(array $entry): mixed => $entry['value'], $entries);
    }

    private function identifier(mixed $value, int|string $fallback): int|string
    {
        return $this->databaseIdentifier($value) ?? $fallback;
    }

    private function databaseIdentifier(mixed $value): int|string|null
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

        return null;
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
                if (!array_key_exists($column, $row)
                    || !$this->sameDatabaseValue($row[$column], $entry['value'])) {
                    continue;
                }
                $matched[$index] = true;

                break;
            }
        }

        return $matched;
    }

    private function query(string $table): QueryBuilder
    {
        return ($this->connection)()->query()->from($table);
    }

    /** @param list<mixed> $values @return list<array<string, mixed>> */
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

    /** @return array{0:string,1:mixed,2:int|string,3:int|string|null,4:string,5:bool,6:string} */
    private function uniqueCheck(int|string $key, mixed $check): array
    {
        if (!is_array($check)) {
            return [$this->stringValue($key), $check, $this->identifier($check, $key), null, 'id', true, 'deleted_at'];
        }

        $value = $check['value'] ?? null;

        return [
            $this->stringValue($check['column'] ?? null),
            $value,
            $this->identifier($check['id'] ?? $check['field'] ?? $value, $key),
            $this->databaseIdentifier($check['ignore'] ?? null),
            $this->stringValue($check['id_column'] ?? 'id') ?: 'id',
            ($check['include_trashed'] ?? true) === true,
            $this->stringValue($check['soft_delete_column'] ?? 'deleted_at') ?: 'deleted_at',
        ];
    }
}
