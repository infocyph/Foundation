<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;

final class DB extends Facade
{
    public static function authSchema(): AuthSchemaInstaller
    {
        return static::manager()->authSchema();
    }

    public static function connection(?string $name = null, bool $fresh = false): Connection
    {
        return static::manager()->connection($name, $fresh);
    }

    public static function manager(): \Infocyph\Foundation\Database\DatabaseManager
    {
        return static::app()->db();
    }

    public static function query(?string $name = null): QueryBuilder
    {
        return static::manager()->query($name);
    }

    public static function table(string $table, ?string $name = null): QueryBuilder
    {
        return static::manager()->table($table, $name);
    }
}
