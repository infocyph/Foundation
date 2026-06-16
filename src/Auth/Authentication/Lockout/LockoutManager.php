<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Lockout;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Cache\CounterStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\LockoutReason;
use Infocyph\Foundation\Auth\Contract\Storage\LockoutStoreInterface;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\ContextValue;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class LockoutManager
{
    public function __construct(
        private CounterStoreInterface $counters,
        private LockoutStoreInterface $locks,
        private AuditEventStoreInterface $audit,
        private AuthIdGeneratorInterface $ids,
        private LockoutConfig $config = new LockoutConfig(),
        private ClockInterface $clock = new SystemClock(),
    ) {}

    public function clearFailures(string $accountId): void
    {
        $this->counters->reset($this->counterKey('login', $accountId));
        $this->counters->reset($this->counterKey('mfa', $accountId));
        $this->counters->reset($this->counterKey('passkey', $accountId));
    }

    public function isLocked(string $accountId): bool
    {
        return $this->locks->isLocked($accountId);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function lock(string $accountId, LockoutReason $reason, ?int $until = null, array $context = []): LockoutResult
    {
        $lockedUntil = $until ?? ($this->clock->now() + $this->config->lockSeconds);
        $this->locks->lock($accountId, $reason, $lockedUntil);
        $this->recordAudit(AuthEventType::LOCKOUT_TRIGGERED, $accountId, ['reason' => $reason->value, 'until' => $lockedUntil] + $context, AuthEventSeverity::WARNING);

        return new LockoutResult(LockoutStatus::LOCKED, $accountId, $reason, $lockedUntil, code: 'account_locked', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordLoginFailure(string $accountId, array $context = []): LockoutResult
    {
        return $this->recordFailure($accountId, 'login', $this->config->maxLoginFailures, LockoutReason::TOO_MANY_LOGIN_ATTEMPTS, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordMfaFailure(string $accountId, array $context = []): LockoutResult
    {
        return $this->recordFailure($accountId, 'mfa', $this->config->maxMfaFailures, LockoutReason::TOO_MANY_MFA_FAILURES, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordPasskeyFailure(string $accountId, array $context = []): LockoutResult
    {
        return $this->recordFailure($accountId, 'passkey', $this->config->maxPasskeyFailures, LockoutReason::TOO_MANY_PASSKEY_FAILURES, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function unlock(string $accountId, array $context = []): LockoutResult
    {
        $this->locks->unlock($accountId);
        $this->clearFailures($accountId);
        $this->recordAudit(AuthEventType::LOCKOUT_CLEARED, $accountId, $context);

        return new LockoutResult(LockoutStatus::UNLOCKED, $accountId, code: 'account_unlocked', context: $context);
    }

    private function counterKey(string $type, string $accountId): string
    {
        return 'lockout:' . $type . ':' . $accountId;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordAudit(AuthEventType $type, string $accountId, array $metadata = [], AuthEventSeverity $severity = AuthEventSeverity::INFO): void
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

    /**
     * @param array<string, mixed> $context
     */
    private function recordFailure(string $accountId, string $type, int $threshold, LockoutReason $reason, array $context): LockoutResult
    {
        $attempts = $this->counters->increment($this->counterKey($type, $accountId), ttlSeconds: $this->config->windowSeconds);

        if ($attempts >= $threshold) {
            return $this->lock($accountId, $reason, null, ['attempts' => $attempts] + $context);
        }

        return new LockoutResult(
            LockoutStatus::FAILURE_RECORDED,
            $accountId,
            $reason,
            attempts: $attempts,
            code: $type . '_failure_recorded',
            context: $context,
        );
    }
}
