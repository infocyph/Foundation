<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Contract\Storage\LockoutReason;
use Infocyph\Foundation\Auth\Contract\Storage\LockoutStoreInterface;

final readonly class DBLayerLockoutStore extends ClockedDBLayerStore implements LockoutStoreInterface
{
    /**
     * @throws \RuntimeException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    #[\Override]
    public function isLocked(string $accountId): bool
    {
        $row = $this->first(
            sprintf('SELECT until_at FROM %s WHERE account_id = ?', $this->table('lockouts')),
            [$accountId],
        );

        if ($row === null) {
            return false;
        }

        $until = $this->intOrNull($row['until_at'] ?? null);
        if ($until !== null && $until <= $this->now()) {
            $this->unlock($accountId);

            return false;
        }

        return true;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function lock(string $accountId, LockoutReason $reason, ?int $until = null): void
    {
        $this->upsertRecord('lockouts', 'account_id', [
            'account_id' => $accountId,
            'reason' => $reason->value,
            'until_at' => $until,
        ]);
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function unlock(string $accountId): void
    {
        $this->deleteWhere('lockouts', 'account_id = ?', [$accountId]);
    }
}
