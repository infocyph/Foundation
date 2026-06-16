<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Gate;

final class AbilityMatcher
{
    public function matches(string $grantedAbility, string $requestedAbility): bool
    {
        if ($grantedAbility === '*' || $grantedAbility === $requestedAbility) {
            return true;
        }

        if (str_ends_with($grantedAbility, ':*')) {
            return str_starts_with($requestedAbility, substr($grantedAbility, 0, -1));
        }

        return false;
    }
}
