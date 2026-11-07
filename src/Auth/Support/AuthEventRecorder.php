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
     * Canonical actor, session and device identities must be passed explicitly
     * from trusted application state, never inferred from arbitrary metadata.
     *
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
            sessionId: $sessionId,
            deviceId: $deviceId,
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
            foreach (array_keys($metadata) as $key) {
                $metadata[$key] = '[REDACTED]';
            }

            return $metadata;
        }

        $safe = [];
        foreach ($metadata as $key => $value) {
            // Preserve word boundaries for ordinary keys while folding all
            // separators and arbitrary casing for sensitive-key matching.
            $name = is_string($key)
                ? strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('-', '_', $key)) ?? $key)
                : '';
            $folded = preg_replace('/[^a-z0-9]/', '', $name) ?? $name;
            $sensitive = $folded !== ''
                && !in_array($folded, ['tokenid', 'tokentype', 'credentialid', 'authorizationid'], true)
                && (
                    preg_match('/(?:^|_)(?:password|passwd|passphrase|secret|token|authorization|credential|signature|otp|pin|seed|bearer|jwt|assertion|cookie|recovery_codes?)(?:s)?(?:_|$)/', $name) === 1
                    || preg_match('/password|passwd|passphrase|secret|token|authorization|credential|signature|seed|bearer|jwt|assertion|cookie|recoverycodes?|apikey|privatekey|authenticatordata|attestationobject/', $folded) === 1
                    || preg_match('/otp|codeverifier|pin(?:$|code|hash|value|secret|digits|number|credential|reset)/', $folded) === 1
                    || $folded === 'code'
                );

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
