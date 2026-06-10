<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

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

    protected function all(string $sql, array $bindings = []): array
    {
        return $this->connection()->select($sql, $bindings);
    }

    protected function connection(): Connection
    {
        return $this->db->connection($this->connection);
    }

    protected function execute(string $sql, array $bindings = []): void
    {
        $this->connection()->statement($sql, $bindings);
    }

    protected function exists(string $sql, array $bindings = []): bool
    {
        return $this->first($sql, $bindings) !== null;
    }

    protected function first(string $sql, array $bindings = []): ?array
    {
        $row = $this->all($sql, $bindings)[0] ?? null;

        return is_array($row) ? $row : null;
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
        return $this->tables->{$name}();
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
}
