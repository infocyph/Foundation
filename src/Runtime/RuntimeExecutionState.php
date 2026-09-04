<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Closure;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Throwable;

/**
 * Mutable state owned by exactly one InterMix execution scope.
 *
 * Shared Foundation services resolve this object on demand instead of retaining
 * execution state themselves. Database connections created here are therefore
 * isolated across concurrent requests/jobs/commands/scheduler invocations.
 */
final class RuntimeExecutionState
{
    private bool $cleaned = false;

    /** @var list<Closure():void> */
    private array $cleanupCallbacks = [];

    /** @var array<string, Connection> */
    private array $connections = [];

    /** @var array<int, Connection> */
    private array $freshConnections = [];

    public function __destruct()
    {
        $this->cleanup(false);
    }

    public function cleanup(bool $throw = true): void
    {
        if ($this->cleaned) {
            return;
        }

        $this->cleaned = true;
        $callbacks = array_reverse($this->cleanupCallbacks);
        $connections = [
            ...array_values($this->connections),
            ...array_values($this->freshConnections),
        ];
        $this->cleanupCallbacks = [];
        $this->connections = [];
        $this->freshConnections = [];

        $failure = $this->cleanupDeferred($callbacks);
        $failure = $this->cleanupConnections($connections, $failure);

        if ($throw && $failure !== null) {
            throw $failure;
        }
    }

    /**
     * Register scope-owned cleanup such as releasing a lock or removing a
     * temporary resource. Callbacks run once in reverse acquisition order.
     *
     * @param callable():void $cleanup
     */
    public function deferCleanup(callable $cleanup): void
    {
        $this->assertOpen();
        $this->cleanupCallbacks[] = Closure::fromCallable($cleanup);
    }

    public function connection(string $name, ConnectionConfig $config): Connection
    {
        $this->assertOpen();

        return $this->connections[$name] ??= new Connection($config, $name);
    }

    public function freshConnection(string $name, ConnectionConfig $config): Connection
    {
        $this->assertOpen();

        $connection = new Connection($config, $name);
        $this->freshConnections[spl_object_id($connection)] = $connection;

        return $connection;
    }

    public function hasDatabaseConnections(): bool
    {
        return $this->connections !== [] || $this->freshConnections !== [];
    }

    private function assertOpen(): void
    {
        if ($this->cleaned) {
            throw new \LogicException('The current Foundation execution state has already been cleaned.');
        }
    }

    /**
     * @param list<Closure():void> $callbacks
     */
    private function cleanupDeferred(array $callbacks): ?Throwable
    {
        $failure = null;
        foreach ($callbacks as $cleanup) {
            try {
                $cleanup();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        return $failure;
    }

    /**
     * @param list<Connection> $connections
     */
    private function cleanupConnections(array $connections, ?Throwable $failure): ?Throwable
    {
        foreach ($connections as $connection) {
            try {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollbackTransaction();
                }
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }

            try {
                $connection->disconnect();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        return $failure;
    }
}
