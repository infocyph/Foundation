<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Event\EventDispatcherInterface;

final class CollectingEventDispatcher implements EventDispatcherInterface
{
    /**
     * @var list<object>
     */
    private array $events = [];

    public function dispatch(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<object>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function flush(): void
    {
        $this->events = [];
    }
}
