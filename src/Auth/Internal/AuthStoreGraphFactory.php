<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Audit\AuthEvent;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Support\ForwardingAuditEventStore;
use Psr\EventDispatcher\EventDispatcherInterface;

final class AuthStoreGraphFactory
{
    public static function forwardingAuditStore(
        AuditEventStoreInterface $storage,
        EventDispatcherInterface $dispatcher,
    ): AuditEventStoreInterface {
        return new ForwardingAuditEventStore(
            $storage,
            static function (AuthEvent $event) use ($dispatcher): void {
                $dispatcher->dispatch($event);
            },
        );
    }
}
