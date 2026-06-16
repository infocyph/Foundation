<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

final readonly class AccessTokenClaims
{
    /**
     * @param list<string> $scopes
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $subjectId,
        public ?string $actorId,
        public int $issuedAt,
        public int $expiresAt,
        public array $scopes = [],
        public array $metadata = [],
    ) {}
}
