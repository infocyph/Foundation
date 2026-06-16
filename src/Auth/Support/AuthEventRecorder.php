<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Audit\AuthEvent;
use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;

final class AuthEventRecorder
{
    /**
     * @param array<string, mixed> $metadata
     */
    public static function record(
        AuditEventStoreInterface $audit,
        AuthIdGeneratorInterface $ids,
        ClockInterface $clock,
        AuthEventType $type,
        ?string $accountId,
        ?string $actorId = null,
        array $metadata = [],
        AuthEventSeverity $severity = AuthEventSeverity::INFO,
        ?string $deviceId = null,
        ?string $sessionId = null,
    ): void {
        $audit->record(new AuthEvent(
            id: $ids->auditEventId(),
            type: $type,
            severity: $severity,
            accountId: $accountId,
            actorId: $actorId ?? $accountId,
            sessionId: $sessionId ?? ContextValue::stringOrNull($metadata, 'session_id'),
            deviceId: $deviceId ?? ContextValue::stringOrNull($metadata, 'device_id'),
            correlationId: $ids->correlationId(),
            occurredAt: $clock->now(),
            metadata: $metadata,
        ));
    }
}
