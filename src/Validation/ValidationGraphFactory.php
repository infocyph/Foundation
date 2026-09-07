<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Database\DBLayerFactory;

final class ValidationGraphFactory
{
    public static function databaseProvider(
        DBLayerFactory $database,
        ?string $connection,
    ): ReqShieldDatabaseProvider {
        return new ReqShieldDatabaseProvider(
            connection: static fn() => $database->connection($connection),
        );
    }
}
