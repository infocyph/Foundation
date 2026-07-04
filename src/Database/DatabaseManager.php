<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;

final readonly class DatabaseManager
{
    public function __construct(
        private ConfigRepository $config,
        private DBLayerFactory $factory,
        private AuthSchemaInstaller $authSchemaInstaller,
    ) {}

    public function authSchema(): AuthSchemaInstaller
    {
        return $this->authSchemaInstaller;
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('database', []);
        }

        return $this->config->get('database.' . $key, $default);
    }

    public function connection(?string $name = null, bool $fresh = false): Connection
    {
        return $this->factory->connection($name, $fresh);
    }

    public function query(?string $name = null): QueryBuilder
    {
        return $this->connection($name)->query();
    }

    public function table(string $table, ?string $name = null): QueryBuilder
    {
        return $this->connection($name)->table($table);
    }

    public function transaction(callable $callback, ?string $name = null, int $attempts = 1): mixed
    {
        return $this->connection($name)->transaction($callback, $attempts);
    }
}
