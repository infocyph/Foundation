<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Audit;

final readonly class AuthEvent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public AuthEventType $type,
        public AuthEventSeverity $severity,
        public ?string $accountId,
        public ?string $actorId,
        public ?string $sessionId,
        public ?string $deviceId,
        public string $correlationId,
        public int $occurredAt,
        public array $metadata = [],
    ) {}
}
