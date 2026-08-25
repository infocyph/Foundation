<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Session\SessionManager;

/**
 * Tracks mutable external/package state touched by the current execution unit.
 */
final class RuntimeContextTracker
{
    private bool $databaseTouched = false;

    /** @var array<class-string, object> */
    private array $dirty = [];

    /** @var array<int, Connection> */
    private array $freshDatabaseConnections = [];

    public function markDatabase(): void
    {
        $this->databaseTouched = true;
    }

    public function markFreshDatabaseConnection(Connection $connection): void
    {
        $this->freshDatabaseConnections[spl_object_id($connection)] = $connection;
    }

    public function markPrincipal(CurrentPrincipalContext $principal): void
    {
        $this->dirty[CurrentPrincipalContext::class] = $principal;
    }

    public function markSession(SessionManager $session): void
    {
        $this->dirty[SessionManager::class] = $session;
    }

    public function reset(): void
    {
        $dirty = $this->dirty;
        $databaseTouched = $this->databaseTouched;
        $freshConnections = $this->freshDatabaseConnections;
        $this->dirty = [];
        $this->databaseTouched = false;
        $this->freshDatabaseConnections = [];
        $failure = null;

        foreach ($dirty as $context) {
            try {
                match (true) {
                    $context instanceof CurrentPrincipalContext => $context->clear(),
                    $context instanceof SessionManager => $context->resetContext(),
                    default => null,
                };
            } catch (\Throwable $exception) {
                $failure ??= $exception;
            }
        }

        foreach ($freshConnections as $connection) {
            try {
                $this->resetConnection($connection);
            } catch (\Throwable $exception) {
                $connection->disconnect();
                $failure ??= $exception;
            }
        }

        if ($databaseTouched) {
            try {
                $this->resetSharedDatabaseRuntime();
            } catch (\Throwable $exception) {
                $failure ??= $exception;
            }
        }

        try {
            $this->flushProcessLocalMemoizers();
        } catch (\Throwable $exception) {
            $failure ??= $exception;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function flushProcessLocalMemoizers(): void
    {
        // Do not autoload optional packages solely for cleanup. When active,
        // process-local memoized values must never cross execution boundaries.
        if (class_exists(Memoizer::class, false)) {
            Memoizer::instance()->flush();
        }

        if (class_exists(OnceMemoizer::class, false)) {
            OnceMemoizer::instance()->flush();
        }
    }

    private function resetConnection(Connection $connection): void
    {
        while ($connection->transactionLevel() > 0) {
            $connection->rollbackTransaction();
        }

        if (!$connection->resetRuntimeStateForReuse()) {
            $connection->disconnect();
        }
    }

    private function resetSharedDatabaseRuntime(): void
    {
        if (!class_exists(DB::class, false)) {
            return;
        }

        $failure = null;
        foreach (DB::getConnections() as $connection) {
            try {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollbackTransaction();
                }
            } catch (\Throwable $exception) {
                $connection->disconnect();
                $failure ??= $exception;
            }
        }

        DB::resetRuntimeState(false);

        if ($failure !== null) {
            throw $failure;
        }
    }
}
