<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Principal;

interface CurrentPrincipalProviderInterface
{
    public function get(): ?PrincipalInterface;

    public function require(): PrincipalInterface;
}
