<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Closure;
use Infocyph\Foundation\Auth\Audit\AuthEvent;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;

final readonly class ForwardingAuditEventStore implements AuditEventStoreInterface
{
    /** @var Closure(AuthEvent): void */
    private Closure $forward;

    /** @param callable(AuthEvent): void $forward */
    public function __construct(
        private AuditEventStoreInterface $store,
        callable $forward,
    ) {
        $this->forward = Closure::fromCallable($forward);
    }

    public function record(AuthEvent $event): void
    {
        $this->store->record($event);
        ($this->forward)($event);
    }
}
