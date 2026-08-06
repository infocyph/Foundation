<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Principal;

use Infocyph\Foundation\Auth\Exception\AuthenticationException;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

final class CurrentPrincipalContext implements CurrentPrincipalProviderInterface
{
    /** @var \WeakMap<object, PrincipalInterface> */
    private \WeakMap $fiberPrincipals;

    private ?PrincipalInterface $mainPrincipal = null;

    public function __construct(private readonly ?RuntimeContextTracker $contexts = null)
    {
        $this->fiberPrincipals = new \WeakMap();
    }

    public function clear(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainPrincipal = null;

            return;
        }

        unset($this->fiberPrincipals[$fiber]);
    }

    public function get(): ?PrincipalInterface
    {
        $fiber = \Fiber::getCurrent();

        return $fiber === null
            ? $this->mainPrincipal
            : ($this->fiberPrincipals[$fiber] ?? null);
    }

    public function require(): PrincipalInterface
    {
        $principal = $this->get();
        if ($principal === null) {
            throw new AuthenticationException('No current principal is available.');
        }

        return $principal;
    }

    public function set(?PrincipalInterface $principal): void
    {
        if ($principal === null) {
            $this->clear();

            return;
        }

        $this->contexts?->markPrincipal($this);

        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainPrincipal = $principal;

            return;
        }

        $this->fiberPrincipals[$fiber] = $principal;
    }
}
