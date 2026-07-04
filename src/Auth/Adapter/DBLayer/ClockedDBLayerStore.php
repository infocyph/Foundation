<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

abstract readonly class ClockedDBLayerStore extends DBLayerStore
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        protected ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

    protected function now(): int
    {
        return $this->clock->now();
    }
}
