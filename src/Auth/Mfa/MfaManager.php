<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\ContextValue;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class MfaManager
{
    public function __construct(
        private MfaFactorStoreInterface $factors,
        private MfaVerifierInterface $verifier,
        private RecoveryCodeServiceInterface $recoveryCodes,
        private TtlStoreInterface $ttl,
        private AuditEventStoreInterface $audit,
        private AuthNotifierInterface $notifier,
        private AuthIdGeneratorInterface $ids,
        private int $challengeTtlSeconds = 300,
        private int $satisfiedTtlSeconds = 900,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function activateFactor(string $accountId, string $factorId, array $context = []): MfaEnrollmentResult
    {
        $factor = $this->findFactor($accountId, $factorId);

        if ($factor === null) {
            return new MfaEnrollmentResult(MfaStatus::INVALID, code: 'mfa_factor_not_found', context: $context);
        }

        $enabledFactor = $factor->activated();
        $this->factors->save($enabledFactor);

        return new MfaEnrollmentResult(MfaStatus::ACTIVATED, $enabledFactor, code: 'mfa_factor_activated', context: $context);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function enrollFactor(string $accountId, MfaFactorType|string $type, string $label, array $metadata = [], bool $enabled = false, int $recoveryCodeCount = 10): MfaEnrollmentResult
    {
        $factor = new MfaFactor(
            id: $this->ids->challengeId(),
            accountId: $accountId,
            type: $type instanceof MfaFactorType ? $type->value : $type,
            label: $label,
            enabled: $enabled,
            createdAt: $this->clock->now(),
            metadata: $metadata,
        );

        $this->factors->save($factor);
        $recoveryCodes = $this->recoveryCodes->generate($accountId, $recoveryCodeCount);
        $this->record(AuthEventType::MFA_ENROLLED, $accountId, ['factor_id' => $factor->id, 'factor_type' => $factor->type] + $metadata, AuthEventSeverity::NOTICE);

        return new MfaEnrollmentResult(MfaStatus::ENROLLED, $factor, $recoveryCodes, 'mfa_factor_enrolled', $metadata);
    }

    public function isSatisfied(string $accountId, ?string $sessionId = null): bool
    {
        return (bool) $this->ttl->get($this->satisfiedKey($accountId, $sessionId), false);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function issueChallenge(string $accountId, MfaChallengePurpose|string $purpose = MfaChallengePurpose::LOGIN, ?string $factorId = null, array $context = []): MfaChallengeResult
    {
        $factor = $factorId !== null ? $this->findFactor($accountId, $factorId) : $this->firstEnabledFactor($accountId);

        if ($factor === null) {
            return new MfaChallengeResult(MfaStatus::INVALID, code: 'mfa_factor_not_available', context: $context);
        }

        $now = $this->clock->now();
        $challenge = new MfaChallenge(
            id: $this->ids->challengeId(),
            accountId: $accountId,
            factorId: $factor->id,
            purpose: $purpose instanceof MfaChallengePurpose ? $purpose->value : $purpose,
            issuedAt: $now,
            expiresAt: $now + $this->challengeTtlSeconds,
            metadata: $context,
        );

        $this->ttl->put($this->challengeKey($challenge->id), $challenge, $this->challengeTtlSeconds);
        $this->record(AuthEventType::MFA_CHALLENGED, $accountId, ['challenge_id' => $challenge->id, 'factor_id' => $factor->id] + $context);
        $this->notifier->send(new AuthNotification(
            AuthNotificationType::MFA_CHALLENGE_REQUESTED,
            $accountId,
            ['challenge_id' => $challenge->id, 'factor_id' => $factor->id, 'purpose' => $challenge->purpose] + $context,
        ));

        return new MfaChallengeResult(MfaStatus::CHALLENGE_ISSUED, $challenge, factor: $factor, code: 'mfa_challenge_issued', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function removeFactor(string $accountId, string $factorId, array $context = []): MfaEnrollmentResult
    {
        $factor = $this->findFactor($accountId, $factorId);

        if ($factor === null) {
            return new MfaEnrollmentResult(MfaStatus::INVALID, code: 'mfa_factor_not_found', context: $context);
        }

        $this->factors->remove($factorId);
        $this->record(AuthEventType::MFA_DISABLED, $accountId, ['factor_id' => $factorId] + $context, AuthEventSeverity::NOTICE);

        return new MfaEnrollmentResult(MfaStatus::REMOVED, $factor, code: 'mfa_factor_removed', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function verifyChallenge(string $challengeId, string $code, array $context = []): MfaChallengeResult
    {
        $challenge = $this->ttl->get($this->challengeKey($challengeId));

        if (!$challenge instanceof MfaChallenge) {
            return new MfaChallengeResult(MfaStatus::INVALID, code: 'mfa_challenge_not_found', context: $context);
        }

        if ($challenge->isExpiredAt($this->clock->now())) {
            $this->ttl->delete($this->challengeKey($challengeId));

            return new MfaChallengeResult(MfaStatus::EXPIRED, $challenge, code: 'mfa_challenge_expired', context: $context);
        }

        $verification = $this->verifier->verify($challenge, $code);

        if (!$verification->verified) {
            return new MfaChallengeResult(MfaStatus::INVALID, $challenge, $verification, code: $verification->reason ?? 'mfa_code_invalid', context: $context);
        }

        $this->ttl->delete($this->challengeKey($challengeId));
        $this->markSatisfied($challenge->accountId, ContextValue::stringOrNull($context, 'session_id'));

        return new MfaChallengeResult(MfaStatus::VERIFIED, $challenge, $verification, $challenge->factorId !== null ? $this->findFactor($challenge->accountId, $challenge->factorId) : null, 'mfa_verified', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function verifyRecoveryCode(string $accountId, string $code, array $context = []): MfaChallengeResult
    {
        $verification = $this->recoveryCodes->verify($accountId, $code);

        if (!$verification->verified) {
            return new MfaChallengeResult(MfaStatus::INVALID, code: $verification->reason ?? 'recovery_code_invalid', context: $context);
        }

        $this->markSatisfied($accountId, ContextValue::stringOrNull($context, 'session_id'));
        $this->record(AuthEventType::RECOVERY_CODE_USED, $accountId, $context, AuthEventSeverity::WARNING);

        return new MfaChallengeResult(MfaStatus::RECOVERY_CODE_VERIFIED, verification: new MfaVerificationResult(true, recoveryCodeUsed: true, context: $context), code: 'recovery_code_verified', context: $context);
    }

    private function challengeKey(string $challengeId): string
    {
        return 'mfa:challenge:' . $challengeId;
    }

    private function findFactor(string $accountId, string $factorId): ?MfaFactor
    {
        foreach ($this->factors->findForAccount($accountId) as $factor) {
            if ($factor->id === $factorId) {
                return $factor;
            }
        }

        return null;
    }

    private function firstEnabledFactor(string $accountId): ?MfaFactor
    {
        foreach ($this->factors->findForAccount($accountId) as $factor) {
            if ($factor->enabled) {
                return $factor;
            }
        }

        return null;
    }

    private function markSatisfied(string $accountId, ?string $sessionId): void
    {
        $this->ttl->put($this->satisfiedKey($accountId, $sessionId), true, $this->satisfiedTtlSeconds);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function record(AuthEventType $type, string $accountId, array $metadata = [], AuthEventSeverity $severity = AuthEventSeverity::INFO): void
    {
        AuthEventRecorder::record(
            $this->audit,
            $this->ids,
            $this->clock,
            $type,
            $accountId,
            metadata: $metadata,
            severity: $severity,
            sessionId: ContextValue::stringOrNull($metadata, 'session_id'),
            deviceId: ContextValue::stringOrNull($metadata, 'device_id'),
        );
    }

    private function satisfiedKey(string $accountId, ?string $sessionId): string
    {
        return 'mfa:satisfied:' . $accountId . ':' . ($sessionId ?? 'global');
    }
}
