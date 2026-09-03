<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Principal;

use Infocyph\Foundation\Auth\Exception\AuthenticationException;
use Psr\Container\ContainerInterface;

final readonly class CurrentPrincipalContext implements CurrentPrincipalProviderInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function clear(): void
    {
        $this->state()->principal = null;
    }

    public function get(): ?PrincipalInterface
    {
        return $this->state()->principal;
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
        $this->state()->principal = $principal;
    }

    private function state(): CurrentPrincipalState
    {
        $state = $this->container->get(CurrentPrincipalState::class);
        if (!$state instanceof CurrentPrincipalState) {
            throw new \LogicException('CurrentPrincipalState binding is invalid.');
        }

        return $state;
    }
}
