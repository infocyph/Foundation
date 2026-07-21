<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Config\ConfigRepository;

abstract class AbstractPrincipalResolver implements PrincipalResolverInterface
{
    public function __construct(
        protected readonly ConfigRepository $config,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    protected function principalForAccount(AccountInterface $account, array $metadata = []): ?PrincipalInterface
    {
        $status = $account->status();
        if ($status === AccountStatus::DISABLED
            || $status === AccountStatus::LOCKED
            || $status === AccountStatus::SUSPENDED
        ) {
            return null;
        }

        $accountId = $account->id();

        return new Principal(
            id: $accountId,
            type: PrincipalType::ACCOUNT,
            accountId: $accountId,
            metadata: $metadata,
        );
    }

    protected function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
