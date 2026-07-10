<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Support;

trait ResolvesContainerService
{
    protected function resolveContainerService(string $id): mixed
    {
        return $this->container->get($id);
    }
}
