<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\Repository;

/**
 * Container-facing bridge that applies Foundation connection selection to a
 * native DBLayer repository.
 */
abstract class DatabaseRepository extends Repository
{
    public function __construct(DBLayerFactory $database)
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
