<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Audit;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;

final readonly class OAuthAuditRecorder
{
    private const array ALLOWED_METADATA = [
        'active',
        'algorithm',
        'authorization_id',
        'audiences',
        'client_id',
        'error',
        'grant_type',
        'key_id',
        'reason',
        'result',
        'scopes',
        'token_type',
    ];

    public function __construct(
        private AuditEventStoreInterface $audit,
        private AuthIdGeneratorInterface $ids,
        private ClockInterface $clock,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function record(
        AuthEventType $type,
        ?string $accountId = null,
        array $metadata = [],
        AuthEventSeverity $severity = AuthEventSeverity::INFO,
    ): void {
        AuthEventRecorder::record(
            audit: $this->audit,
            ids: $this->ids,
            clock: $this->clock,
            type: $type,
            accountId: $accountId,
            actorId: $accountId,
            metadata: $this->sanitize($metadata),
            severity: $severity,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata): array
    {
        /** @var array<string, mixed> $safe */
        $safe = [];
        foreach (self::ALLOWED_METADATA as $key) {
            if (!array_key_exists($key, $metadata)) {
                continue;
            }
            $value = $metadata[$key];
            if (is_bool($value) || is_int($value) || is_string($value) || $value === null) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 2048) : $value;

                continue;
            }
            if (in_array($key, ['scopes', 'audiences'], true) && is_array($value)) {
                $safe[$key] = array_slice(array_values(array_filter(
                    $value,
                    static fn(mixed $item): bool => is_string($item) && $item !== '',
                )), 0, 64);
            }
        }

        return $safe;
    }
}
