<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Consent;

final readonly class OAuthConsent
{
    /**
     * @param list<string> $scopes
     * @param list<string> $audiences
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public string $clientId,
        public string $scopeFingerprint,
        public array $scopes,
        public array $audiences,
        public int $grantedAt,
        public ?int $revokedAt = null,
        public array $metadata = [],
    ) {}

    public function active(): bool
    {
        return $this->revokedAt === null;
    }
}
