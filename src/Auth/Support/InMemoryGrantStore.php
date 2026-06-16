<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authorization\Grant\AccessGrant;
use Infocyph\Foundation\Auth\Authorization\Grant\GrantStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;

final class InMemoryGrantStore implements GrantStoreInterface
{
    /**
     * @var array<string, AccessGrant>
     */
    private array $grants = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function grantsForPrincipal(string $principalId): array
    {
        return array_values(array_filter(
            $this->grants,
            static fn(AccessGrant $grant): bool => $grant->principalId === $principalId,
        ));
    }

    public function revoke(string $grantId): void
    {
        $grant = $this->grants[$grantId] ?? null;

        if ($grant === null || $grant->isRevoked()) {
            return;
        }

        $this->grants[$grantId] = new AccessGrant(
            id: $grant->id,
            principalId: $grant->principalId,
            permission: $grant->permission,
            resourceType: $grant->resourceType,
            resourceId: $grant->resourceId,
            expiresAt: $grant->expiresAt,
            revokedAt: $this->clock->now(),
            metadata: $grant->metadata,
        );
    }

    public function save(AccessGrant $grant): void
    {
        $this->grants[$grant->id] = $grant;
    }
}
