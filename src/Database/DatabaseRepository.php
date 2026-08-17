<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\Repository;

/**
 * Container-facing bridge to DBLayer's repository implementation.
 */
abstract class DatabaseRepository extends Repository
{
    public function __construct(DatabaseManager $database)
    {
        $connection = $database->connection($this->connectionName());

        parent::__construct(
            $connection,
            $connection->getExecutorInstance(),
            DB::resultProcessor(),
        );
    }

    protected function connectionName(): ?string
    {
        return null;
    }
}
