<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

use Infocyph\AuthLayer\Audit\AuthEvent;
use Infocyph\AuthLayer\Contract\Storage\AuditEventStoreInterface;

final readonly class DBLayerAuditEventStore extends DBLayerStore implements AuditEventStoreInterface
{
    public function record(AuthEvent $event): void
    {
        $this->execute(
            sprintf('INSERT INTO %s (id, type, severity, account_id, actor_id, session_id, device_id, correlation_id, occurred_at, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $this->table('auditEvents')),
            [
                $event->id,
                $event->type->value,
                $event->severity->value,
                $event->accountId,
                $event->actorId,
                $event->sessionId,
                $event->deviceId,
                $event->correlationId,
                $event->occurredAt,
                DBLayerJson::encode($event->metadata),
            ],
        );
    }
}
