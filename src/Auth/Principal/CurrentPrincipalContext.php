<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Principal;

use Infocyph\Foundation\Auth\Exception\AuthenticationException;

final class CurrentPrincipalContext implements CurrentPrincipalProviderInterface
{
    private ?PrincipalInterface $principal = null;

    public function clear(): void
    {
        $this->principal = null;
    }

    public function get(): ?PrincipalInterface
    {
        return $this->principal;
    }

    public function require(): PrincipalInterface
    {
        if ($this->principal === null) {
            throw new AuthenticationException('No current principal is available.');
        }

        return $this->principal;
    }

    public function set(?PrincipalInterface $principal): void
    {
        $this->principal = $principal;
    }
}
