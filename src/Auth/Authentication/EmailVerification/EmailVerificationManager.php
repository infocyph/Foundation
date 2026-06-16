<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\EmailVerification;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\EmailVerificationStoreInterface;
use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\ContextValue;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class EmailVerificationManager
{
    private const string REQUEST_CONTEXT_KEY = 'request_id';

    public function __construct(
        private EmailVerificationTokenServiceInterface $tokens,
        private EmailVerificationStoreInterface $store,
        private AccountStoreInterface $accounts,
        private AuthNotifierInterface $notifier,
        private AuditEventStoreInterface $audit,
        private AuthIdGeneratorInterface $ids,
        private int $ttlSeconds = 3600,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function issue(string $accountId, string $email, array $context = []): EmailVerificationResult
    {
        $now = $this->clock->now();
        $requestId = $this->ids->challengeId();
        $request = new EmailVerificationRequest($requestId, $accountId, $email, $now, $now + $this->ttlSeconds, context: $context);

        $this->store->save($request);
        $requestContext = [self::REQUEST_CONTEXT_KEY => $requestId];
        $token = $this->tokens->issue($accountId, $email, $requestContext + $context);
        $this->notifier->send(new AuthNotification(AuthNotificationType::EMAIL_VERIFICATION_REQUESTED, $accountId, ['email' => $email] + $requestContext + ['token' => $token] + $context));
        $this->recordEvent(AuthEventType::EMAIL_VERIFICATION_REQUESTED, $accountId, $context, ['email' => $email] + $requestContext, AuthEventSeverity::INFO);

        return new EmailVerificationResult(EmailVerificationStatus::ISSUED, $request, $token, 'email_verification_requested', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function verify(string $token, array $context = []): EmailVerificationResult
    {
        $now = $this->clock->now();
        $verification = $this->tokens->verify($token);

        if (!$verification->verified) {
            return $this->invalidVerificationResult($verification->failureReason ?? 'invalid_token', $context);
        }

        $request = $this->resolveRequest($verification);

        if ($request === null) {
            return $this->missingVerificationResult($context);
        }

        if ($request->isConsumed()) {
            return $this->consumedVerificationResult($request, $context);
        }

        if ($request->isExpiredAt($now)) {
            return $this->expiredVerificationResult($request, $context);
        }

        return $this->completeVerification($request, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function completeVerification(EmailVerificationRequest $request, array $context): EmailVerificationResult
    {
        $this->store->consume($request->id);
        $this->accounts->markVerified($request->accountId, $this->clock->now());
        $this->recordEvent(
            AuthEventType::EMAIL_VERIFIED,
            $request->accountId,
            $context,
            [self::REQUEST_CONTEXT_KEY => $request->id, 'email' => $request->email],
            AuthEventSeverity::INFO,
        );

        return new EmailVerificationResult(EmailVerificationStatus::VERIFIED, $request, code: 'email_verified', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function consumedVerificationResult(EmailVerificationRequest $request, array $context): EmailVerificationResult
    {
        return new EmailVerificationResult(EmailVerificationStatus::CONSUMED, $request, code: 'verification_already_consumed', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function expiredVerificationResult(EmailVerificationRequest $request, array $context): EmailVerificationResult
    {
        return new EmailVerificationResult(EmailVerificationStatus::EXPIRED, $request, code: 'verification_expired', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function invalidVerificationResult(string $reason, array $context): EmailVerificationResult
    {
        return new EmailVerificationResult(EmailVerificationStatus::INVALID, code: $reason, context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function missingVerificationResult(array $context): EmailVerificationResult
    {
        return new EmailVerificationResult(EmailVerificationStatus::INVALID, code: 'verification_request_not_found', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $metadata
     */
    private function recordEvent(
        AuthEventType $type,
        string $accountId,
        array $context = [],
        array $metadata = [],
        AuthEventSeverity $severity = AuthEventSeverity::INFO,
    ): void {
        AuthEventRecorder::record(
            $this->audit,
            $this->ids,
            $this->clock,
            $type,
            $accountId,
            metadata: $metadata + $context,
            severity: $severity,
            sessionId: ContextValue::stringOrNull($context, 'session_id'),
            deviceId: ContextValue::stringOrNull($context, 'device_id'),
        );
    }

    private function resolveRequest(TokenVerificationResult $verification): ?EmailVerificationRequest
    {
        $requestId = $verification->claims[self::REQUEST_CONTEXT_KEY] ?? $verification->tokenId;

        if (!is_string($requestId) || $requestId === '') {
            return null;
        }

        return $this->store->find($requestId);
    }
}
