<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\Query\ConnectionRepository;

/**
 * Container-facing bridge that applies Foundation connection selection to a
 * native DBLayer instance-owned repository.
 */
abstract class DatabaseRepository extends ConnectionRepository
{
    /**
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function __construct(DBLayerFactory $database)
    {
        parent::__construct($database->connection($this->connectionName()));
    }

    protected function connectionName(): ?string
    {
        return null;
    }
}
