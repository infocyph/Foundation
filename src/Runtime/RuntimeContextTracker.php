<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;
use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Session\SessionManager;

/**
 * Tracks mutable external/package state touched by the current execution unit.
 */
final class RuntimeContextTracker
{
    /** @var array<class-string, object> */
    private array $dirty = [];

    private bool $databaseTouched = false;

    public function markDatabase(): void
    {
        $this->databaseTouched = true;
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
        $this->dirty = [];
        $this->databaseTouched = false;
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

        if ($databaseTouched) {
            try {
                $this->resetDatabaseRuntime();
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

    private function resetDatabaseRuntime(): void
    {
        if (!class_exists(DB::class, false)) {
            return;
        }

        foreach (DB::getConnections() as $connection) {
            try {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollbackTransaction();
                }
            } catch (\Throwable) {
                $connection->disconnect();
            }
        }

        DB::resetRuntimeState(false);
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
}
