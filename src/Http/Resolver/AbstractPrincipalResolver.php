<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Config\ConfigRepository;
use Psr\Container\ContainerInterface;

abstract class AbstractPrincipalResolver implements PrincipalResolverInterface
{
    public function __construct(
        protected readonly ConfigRepository $config,
        protected readonly ContainerInterface $container,
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

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        $service = $this->container->get($id);
        if (!$service instanceof $id) {
            throw new \LogicException(sprintf('Invalid principal resolver service: %s.', $id));
        }

        return $service;
    }

    protected function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
