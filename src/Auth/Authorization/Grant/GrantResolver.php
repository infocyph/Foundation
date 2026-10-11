<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Grant;

use Infocyph\Foundation\Auth\Authorization\Gate\Ability;
use Infocyph\Foundation\Auth\Authorization\Gate\AbilityMatcher;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class GrantResolver
{
    public function __construct(
        private GrantStoreInterface $grants,
        private AbilityMatcher $matcher = new AbilityMatcher(),
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @return list<AccessGrant>
     */
    public function forPrincipal(string $principalId, Ability $ability): array
    {
        $now = $this->clock->now();
        $matches = [];

        foreach ($this->grants->grantsForPrincipal($principalId) as $grant) {
            if (!$this->eligible($grant, $ability, $now)) {
                continue;
            }

            $matches[] = $grant;
        }

        return $matches;
    }
    private function eligible(AccessGrant $grant, Ability $ability, int $now): bool
    {
        if ($grant->isRevoked() || $grant->isExpiredAt($now)) {
            return false;
        }
        if (!$this->matcher->matches($grant->permission, $ability->name)) {
            return false;
        }

        return ($grant->resourceType === null || $grant->resourceType === $ability->resourceType)
            && ($grant->resourceId === null || $grant->resourceId === $ability->resourceId);
    }

}
