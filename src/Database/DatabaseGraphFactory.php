<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\Connection\Connection;

final class DatabaseGraphFactory
{
    public static function connection(DBLayerFactory $factory): Connection
    {
        return $factory->connection();
    }
}
