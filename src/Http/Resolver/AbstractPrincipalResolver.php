<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\AuthLayer\Account\AccountInterface;
use Infocyph\AuthLayer\Account\AccountStatus;
use Infocyph\AuthLayer\Principal\Principal;
use Infocyph\AuthLayer\Principal\PrincipalInterface;
use Infocyph\AuthLayer\Principal\PrincipalType;

abstract class AbstractPrincipalResolver implements PrincipalResolverInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    protected function principalForAccount(AccountInterface $account, array $metadata = []): ?PrincipalInterface
    {
        if (in_array($account->status(), [
            AccountStatus::DISABLED,
            AccountStatus::LOCKED,
            AccountStatus::SUSPENDED,
        ], true)) {
            return null;
        }

        return new Principal(
            id: $account->id(),
            type: PrincipalType::ACCOUNT,
            accountId: $account->id(),
            metadata: $metadata,
        );
    }
}
