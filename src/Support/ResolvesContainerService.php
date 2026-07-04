<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Support;

use Infocyph\InterMix\DI\Container;

trait ResolvesContainerService
{
    protected Container $container;

    protected function resolveContainerService(string $id): mixed
    {
        return $this->container->get($id);
    }
}
