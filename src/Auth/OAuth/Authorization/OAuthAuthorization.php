<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

final readonly class OAuthAuthorization
{
    /**
     * @param list<string> $scopes
     * @param list<string> $audiences
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public ?string $accountId,
        public array $scopes,
        public array $audiences,
        public int $createdAt,
        public ?int $expiresAt = null,
        public ?int $revokedAt = null,
        public array $metadata = [],
    ) {}

    public function activeAt(int $now): bool
    {
        return $this->revokedAt === null && ($this->expiresAt === null || $this->expiresAt > $now);
    }
}
