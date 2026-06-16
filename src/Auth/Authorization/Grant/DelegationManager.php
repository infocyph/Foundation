<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Grant;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\ContextValue;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class DelegationManager
{
    public function __construct(
        private GrantStoreInterface $grants,
        private AuditEventStoreInterface $audit,
        private AuthIdGeneratorInterface $ids,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public function grant(string $principalId, string $permission, ?string $resourceType = null, ?string $resourceId = null, ?int $expiresAt = null, array $metadata = []): DelegationResult
    {
        $grant = new AccessGrant(
            id: $this->ids->grantId(),
            principalId: $principalId,
            permission: $permission,
            resourceType: $resourceType,
            resourceId: $resourceId,
            expiresAt: $expiresAt,
            metadata: $metadata,
        );

        $this->grants->save($grant);
        $this->recordAudit(AuthEventType::DELEGATED_ACCESS_GRANTED, $principalId, ['grant_id' => $grant->id, 'permission' => $permission] + $metadata);

        return new DelegationResult(DelegationStatus::GRANTED, grant: $grant, code: 'delegated_access_granted', context: $metadata);
    }

    public function listForPrincipal(string $principalId): DelegationResult
    {
        return new DelegationResult(DelegationStatus::LISTED, grants: $this->grants->grantsForPrincipal($principalId), code: 'delegated_access_listed');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function revoke(string $grantId, ?string $principalId = null, array $metadata = []): DelegationResult
    {
        $this->grants->revoke($grantId);
        $this->recordAudit(AuthEventType::DELEGATED_ACCESS_REVOKED, $principalId, ['grant_id' => $grantId] + $metadata);

        return new DelegationResult(DelegationStatus::REVOKED, code: 'delegated_access_revoked', context: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordAudit(AuthEventType $type, ?string $principalId, array $metadata): void
    {
        AuthEventRecorder::record(
            $this->audit,
            $this->ids,
            $this->clock,
            $type,
            $principalId,
            metadata: $metadata,
            severity: AuthEventSeverity::NOTICE,
            sessionId: ContextValue::stringOrNull($metadata, 'session_id'),
            deviceId: ContextValue::stringOrNull($metadata, 'device_id'),
        );
    }
}
