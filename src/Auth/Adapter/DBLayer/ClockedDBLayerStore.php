<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Psr\Container\ContainerInterface;

abstract readonly class ClockedDBLayerStore extends DBLayerStore
{
    public function __construct(
        ContainerInterface $services,
        AuthTables $tables,
        protected ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($services, $tables, $connection);
    }

    protected function now(): int
    {
        return $this->clock->now();
    }
}
