<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Testing;

use Infocyph\Foundation\Database\DatabaseMigrationManager;
use Infocyph\Foundation\Database\DBLayerFactory;

/**
 * Public database testing and transaction helpers.
 *
 * @api
 */
final readonly class DatabaseTestManager
{
    public function __construct(
        private DBLayerFactory $database,
        private DatabaseMigrationManager $migrations,
    ) {}

    /**
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function begin(?string $connection = null): void
    {
        $this->database->connection($connection)->begin();
    }

    /**
     * @return list<string>
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function refresh(?string $connection = null): array
    {
        return $this->migrations->runner($connection)->refresh(true);
    }

    /**
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function rollback(?string $connection = null): void
    {
        $database = $this->database->connection($connection);

        while ($database->transactionLevel() > 0) {
            $database->rollbackTransaction();
        }
    }

    /**
     * @template T
     * @param callable():T $test
     * @return T
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function transaction(callable $test, ?string $connection = null): mixed
    {
        $this->begin($connection);

        try {
            return $test();
        } finally {
            $this->rollback($connection);
        }
    }
}
