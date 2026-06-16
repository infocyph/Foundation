<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Impersonation;

final readonly class ImpersonationSession
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $actorId,
        public string $targetAccountId,
        public int $startedAt,
        public array $metadata = [],
    ) {}
}
