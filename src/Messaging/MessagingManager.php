<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Testing\RecordingSender;

final class MessagingManager
{
    private ?RecordingSender $fake = null;

    public function __construct(
        private readonly MessageBus $bus,
        private readonly EventDispatcher $events,
    ) {}

    public function dispatch(object $message): Envelope
    {
        if ($this->fake !== null) {
            return $this->fake->send(Envelope::wrap($message), 'default');
        }

        return $this->bus->dispatch($message);
    }

    public function dispatchNotification(object $notification): Envelope
    {
        return $this->dispatch($notification);
    }

    public function event(object $event): object
    {
        if ($this->fake !== null) {
            $this->fake->send(Envelope::wrap($event), 'events');

            return $event;
        }

        return $this->events->dispatch($event);
    }

    public function fake(): RecordingSender
    {
        return $this->fake ??= new RecordingSender();
    }

    public function isFaking(): bool
    {
        return $this->fake !== null;
    }

    /**
     * @return array{configured:bool,handlers:int,listeners:int,routes:int,scheduled_messages:int}
     */
    public function readiness(
        int $handlers,
        int $listeners,
        int $routes,
        int $scheduledMessages,
    ): array {
        return [
            'configured' => $handlers + $listeners + $routes + $scheduledMessages > 0,
            'handlers' => $handlers,
            'listeners' => $listeners,
            'routes' => $routes,
            'scheduled_messages' => $scheduledMessages,
        ];
    }

    public function restore(): void
    {
        $this->fake = null;
    }
}
