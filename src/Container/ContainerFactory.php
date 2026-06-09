<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Container;

use Infocyph\InterMix\DI\Container;

final class ContainerFactory
{
    public function create(?string $alias = null): Container
    {
        return new Container($alias ?? $this->defaultAlias());
    }

    private function defaultAlias(): string
    {
        return 'foundation.' . bin2hex(random_bytes(8));
    }
}
