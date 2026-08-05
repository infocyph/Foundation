<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\Foundation\Session\SessionManager;

/**
 * Tracks mutable contexts touched by the current request.
 */
final class RuntimeContextTracker
{
    /** @var array<class-string, object> */
    private array $dirty = [];

    public function markDatabase(DatabaseManager $database): void
    {
        $this->dirty[DatabaseManager::class] = $database;
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
        $this->dirty = [];
        $failure = null;

        foreach ($dirty as $context) {
            try {
                match (true) {
                    $context instanceof CurrentPrincipalContext => $context->clear(),
                    $context instanceof SessionManager => $context->resetContext(),
                    $context instanceof DatabaseManager => $context->resetUnitOfWork(),
                    default => null,
                };
            } catch (\Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}
