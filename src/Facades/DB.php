<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\DatabaseManager;

final class DB extends Facade
{
    public static function authSchema(): AuthSchemaInstaller
    {
        return self::manager()->authSchema();
    }

    public static function connection(?string $name = null, bool $fresh = false): Connection
    {
        return self::manager()->connection($name, $fresh);
    }

    public static function manager(): DatabaseManager
    {
        return self::app()->db();
    }

    public static function query(?string $name = null): QueryBuilder
    {
        return self::manager()->query($name);
    }

    public static function table(string $table, ?string $name = null): QueryBuilder
    {
        return self::manager()->table($table, $name);
    }
}
