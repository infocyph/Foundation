<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Testing;

use Infocyph\Foundation\Database\DatabaseManager;

final readonly class DatabaseTestManager
{
    public function __construct(private DatabaseManager $database) {}

    public function begin(?string $connection = null): void
    {
        $this->database->connection($connection)->begin();
    }

    /** @return list<string> */
    public function refresh(?string $connection = null): array
    {
        return $this->database->migrations()->runner($connection)->refresh(true);
    }

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
