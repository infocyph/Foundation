<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Storage;

use Infocyph\Foundation\Auth\Audit\AuthEvent;

interface AuditEventStoreInterface
{
    public function record(AuthEvent $event): void;
}
