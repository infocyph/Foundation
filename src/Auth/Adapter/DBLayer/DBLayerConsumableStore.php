<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

abstract readonly class DBLayerConsumableStore extends ClockedDBLayerStore
{
    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    protected function consumeRequest(string $table, string $requestId): void
    {
        $this->updateWhere($table, ['consumed_at' => $this->now()], 'id = ?', [$requestId]);
    }

    /**
     * @template TRequest
     * @param callable(array<string, mixed>): TRequest $mapper
     * @return TRequest|null
     * @throws \RuntimeException
     */
    protected function findRequest(string $table, callable $mapper, string $requestId): mixed
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table($table)),
            $mapper,
            [$requestId],
        );
    }

    /**
     * @throws \RuntimeException
     */
    protected function requestWasConsumed(string $table, string $requestId): bool
    {
        $row = $this->first(
            sprintf('SELECT consumed_at FROM %s WHERE id = ?', $this->table($table)),
            [$requestId],
        );

        return $row !== null && $this->intOrNull($row['consumed_at'] ?? null) !== null;
    }
}
