<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\InterMix\DI\Container;

/**
 * Clears only capabilities that were already activated for the unit of work.
 */
final readonly class RuntimeContextResetter
{
    public function __construct(private Container $container) {}

    public function reset(): void
    {
        if ($this->container->has(CurrentPrincipalContext::class)) {
            $principal = $this->container->get(CurrentPrincipalContext::class);
            if ($principal instanceof CurrentPrincipalContext) {
                $principal->clear();
            }
        }

        if ($this->container->has(SessionManager::class)) {
            $sessions = $this->container->get(SessionManager::class);
            if ($sessions instanceof SessionManager) {
                $sessions->resetContext();
            }
        }

        if ($this->container->has(DatabaseManager::class)) {
            $database = $this->container->get(DatabaseManager::class);
            if ($database instanceof DatabaseManager) {
                $database->resetUnitOfWork();
            }
        }
    }
}
