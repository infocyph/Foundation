<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutStatus;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\ContextValue;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class PasskeyManager
{
    public function __construct(
        private PasskeyServiceInterface $service,
        private PasskeyCredentialStoreInterface $credentials,
        private AuditEventStoreInterface $audit,
        private AuthNotifierInterface $notifier,
        private AuthIdGeneratorInterface $ids,
        private ?LockoutManager $lockouts = null,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function finishAuthentication(PasskeyAuthenticationResult $result, array $context = []): PasskeyAuthenticationOutcome
    {
        $verification = $this->service->finishAuthentication($result);
        $code = $verification->verified ? 'passkey_verified' : ($verification->reason ?? 'passkey_invalid');

        if ($verification->verified && $verification->accountId !== null) {
            if ($verification->credentialId !== null && $verification->signCount !== null) {
                $this->credentials->updateUsage($verification->credentialId, $verification->signCount, $this->clock->now());
            }

            $this->lockouts?->clearFailures($verification->accountId);
            $this->record(AuthEventType::PASSKEY_USED, $verification->accountId, ['credential_id' => $verification->credentialId] + $context);
        } elseif ($verification->accountId !== null) {
            $lockout = $this->lockouts?->recordPasskeyFailure(
                $verification->accountId,
                ['credential_id' => $verification->credentialId, 'reason' => $verification->reason] + $context,
            );

            if ($lockout?->status === LockoutStatus::LOCKED) {
                $code = $lockout->code ?? 'account_locked';
            } elseif ($lockout?->code !== null) {
                $code = $lockout->code;
            }
        }

        return new PasskeyAuthenticationOutcome(
            $verification->verified ? PasskeyAuthenticationStatus::VERIFIED : PasskeyAuthenticationStatus::INVALID,
            verification: $verification,
            code: $code,
            context: $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function finishRegistration(PasskeyRegistrationResult $result, array $context = []): PasskeyRegistrationOutcome
    {
        $credential = $this->service->finishRegistration($result);
        $this->credentials->save($credential);
        $metadata = [
            'passkey_id' => $credential->id,
            'credential_id' => $credential->credentialId,
        ] + $context;
        $this->record(AuthEventType::PASSKEY_REGISTERED, $credential->accountId, $metadata, AuthEventSeverity::NOTICE);
        $this->notifier->send(new AuthNotification(AuthNotificationType::PASSKEY_REGISTERED, $credential->accountId, $metadata));

        return new PasskeyRegistrationOutcome(PasskeyRegistrationStatus::REGISTERED, credential: $credential, code: 'passkey_registered', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function revokeCredential(string $accountId, string $credentialId, array $context = []): void
    {
        $this->credentials->revoke($credentialId);
        $this->record(AuthEventType::PASSKEY_REMOVED, $accountId, ['credential_id' => $credentialId] + $context, AuthEventSeverity::NOTICE);
        $this->notifier->send(new AuthNotification(AuthNotificationType::PASSKEY_REMOVED, $accountId, ['credential_id' => $credentialId] + $context));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function startAuthentication(?string $accountId = null, array $context = []): PasskeyAuthenticationOutcome
    {
        $challenge = $this->service->startAuthentication($accountId);

        return new PasskeyAuthenticationOutcome(PasskeyAuthenticationStatus::STARTED, $challenge, code: 'passkey_authentication_started', context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function startRegistration(string $accountId, array $context = []): PasskeyRegistrationOutcome
    {
        $challenge = $this->service->startRegistration($accountId);

        return new PasskeyRegistrationOutcome(PasskeyRegistrationStatus::STARTED, $challenge, code: 'passkey_registration_started', context: $context);
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
}
