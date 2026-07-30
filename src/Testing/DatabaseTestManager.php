<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Testing;

use Infocyph\Foundation\Database\DatabaseManager;

final readonly class DatabaseTestManager
{
    public function __construct(private DatabaseManager $database) {}

    public function begin(?string $connection = null): void
    {
        $this->database->beginTransaction($connection);
    }

    /**
     * @return list<string>
     */
    public function refresh(?string $connection = null): array
    {
        return $this->database->migrations()->runner($connection)->refresh(true);
    }

    public function rollback(?string $connection = null): void
    {
        while ($this->database->transactionLevel($connection) > 0) {
            $this->database->rollback($connection);
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
