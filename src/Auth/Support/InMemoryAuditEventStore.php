<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Audit\AuthEvent;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;

final class InMemoryAuditEventStore implements AuditEventStoreInterface
{
    /**
     * @var list<AuthEvent>
     */
    private array $events = [];

    /**
     * @return list<AuthEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function flush(): void
    {
        $this->events = [];
    }

    public function record(AuthEvent $event): void
    {
        $this->events[] = $event;
    }
}
