<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\RememberMe;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\RememberTokenStoreInterface;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\ContextValue;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class RememberMeManager
{
    public function __construct(
        private RememberTokenServiceInterface $tokens,
        private RememberTokenStoreInterface $store,
        private AuditEventStoreInterface $audit,
        private AuthIdGeneratorInterface $ids,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function issue(string $accountId, string $deviceId, array $context = []): RememberMeResult
    {
        $issued = $this->tokens->issue($accountId, $deviceId);
        $record = new RememberTokenRecord(
            id: $this->ids->challengeId(),
            accountId: $accountId,
            deviceId: $deviceId,
            selector: $issued->selector,
            verifierHash: $issued->verifierHash,
            familyId: $issued->familyId,
            issuedAt: $this->clock->now(),
            expiresAt: $issued->expiresAt,
            metadata: $context,
        );

        $this->store->save($record);
        $this->recordAudit(AuthEventType::REMEMBER_TOKEN_ISSUED, $accountId, $deviceId, ['selector' => $issued->selector] + $context);

        return new RememberMeResult(RememberTokenStatus::ISSUED, token: $issued, record: $record, code: 'remember_token_issued', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function revokeFamily(string $familyId, ?string $accountId = null, ?string $deviceId = null, array $context = []): void
    {
        $this->store->revokeFamily($familyId);
        $this->recordAudit(AuthEventType::REMEMBER_TOKEN_REVOKED, $accountId, $deviceId, ['family_id' => $familyId] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function rotate(RememberTokenRecord $current, array $context = []): RememberMeResult
    {
        $issued = $this->tokens->issue($current->accountId, $current->deviceId);
        $replacement = new RememberTokenRecord(
            id: $this->ids->challengeId(),
            accountId: $current->accountId,
            deviceId: $current->deviceId,
            selector: $issued->selector,
            verifierHash: $issued->verifierHash,
            familyId: $current->familyId,
            issuedAt: $this->clock->now(),
            expiresAt: $issued->expiresAt,
            metadata: $context ?: $current->metadata,
        );

        $this->store->rotate($current->id, $replacement);

        return new RememberMeResult(RememberTokenStatus::ROTATED, token: $issued, record: $replacement, code: 'remember_token_rotated', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function verify(string $token, array $context = []): RememberMeResult
    {
        $verification = $this->tokens->verify($token);
        $record = $verification->record;

        if ($verification->suspiciousReuse && $record !== null) {
            $this->store->revokeFamily($record->familyId);
            $this->recordAudit(AuthEventType::REMEMBER_TOKEN_REVOKED, $record->accountId, $record->deviceId, ['reason' => 'suspicious_reuse', 'selector' => $record->selector] + $context, AuthEventSeverity::WARNING);

            return new RememberMeResult(RememberTokenStatus::REUSED, record: $record, code: $verification->failureReason ?? 'remember_token_reused', context: $context);
        }

        if (!$verification->verified || $record === null || $this->store->wasFamilyRevoked($record->familyId)) {
            return new RememberMeResult(RememberTokenStatus::INVALID, code: $verification->failureReason ?? 'invalid_remember_token', context: $context);
        }

        if ($record->isRevoked() || $record->isExpiredAt($this->clock->now())) {
            return new RememberMeResult(RememberTokenStatus::EXPIRED, record: $record, code: 'remember_token_expired', context: $context);
        }

        $usedAt = $this->clock->now();
        $this->store->markUsed($record->id, $usedAt);
        $record = $record->withLastUsedAt($usedAt);

        return new RememberMeResult(RememberTokenStatus::VERIFIED, record: $record, code: 'remember_token_verified', context: $context);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordAudit(AuthEventType $type, ?string $accountId, ?string $deviceId, array $metadata, AuthEventSeverity $severity = AuthEventSeverity::INFO): void
    {
        AuthEventRecorder::record(
            $this->audit,
            $this->ids,
            $this->clock,
            $type,
            $accountId,
            metadata: $metadata,
            severity: $severity,
            deviceId: $deviceId,
            sessionId: ContextValue::stringOrNull($metadata, 'session_id'),
        );
    }
}
