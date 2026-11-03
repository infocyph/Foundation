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
            metadata: self::safeMetadata($metadata),
        ));
    }

    /**
     * Audit stores must never persist credentials, including values nested in
     * caller-supplied context arrays or passed directly to this recorder.
     *
     * @template TKey of array-key
     * @param array<TKey, mixed> $metadata
     * @return array<TKey, mixed>
     */
    private static function safeMetadata(array $metadata, int $depth = 0): array
    {
        if ($depth >= 8) {
            return ['redacted' => '[REDACTED]'];
        }

        $safe = [];
        foreach ($metadata as $key => $value) {
            $name = is_string($key)
                ? strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('-', '_', $key)) ?? $key)
                : '';
            $sensitive = $name !== ''
                && !in_array($name, ['token_id', 'token_type', 'credential_id', 'authorization_id'], true)
                && preg_match('/(?:^|_)(?:password|passwd|secret|token|authorization|credential|signature|otp|cookie|recovery_code)(?:_|$)/', $name) === 1;
            $sensitive = $sensitive || in_array($name, ['code', 'code_verifier', 'api_key', 'private_key', 'private_key_path', 'authenticator_data', 'attestation_object'], true);

            $safe[$key] = match (true) {
                $value === null => null,
                $sensitive => '[REDACTED]',
                is_array($value) => self::safeMetadata($value, $depth + 1),
                is_object($value), is_resource($value) => '[REDACTED]',
                default => $value,
            };
        }

        return $safe;
    }
}
