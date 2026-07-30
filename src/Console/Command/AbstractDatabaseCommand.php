<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Database\DatabaseMigrationManager;

abstract class AbstractDatabaseCommand extends AbstractFoundationCommand
{
    public function __construct(protected readonly Application $application) {}

    final protected function connection(): ?string
    {
        return $this->options()->nullableString('connection');
    }

    final protected function migrations(): DatabaseMigrationManager
    {
        return $this->application->db()->migrations();
    }
}
