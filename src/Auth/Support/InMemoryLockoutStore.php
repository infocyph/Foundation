<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\LockoutReason;
use Infocyph\Foundation\Auth\Contract\Storage\LockoutStoreInterface;

final class InMemoryLockoutStore implements LockoutStoreInterface
{
    /**
     * @var array<string, array{reason: LockoutReason, until: int|null}>
     */
    private array $locks = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function isLocked(string $accountId): bool
    {
        $lock = $this->locks[$accountId] ?? null;

        if ($lock === null) {
            return false;
        }

        if ($lock['until'] !== null && $lock['until'] <= $this->clock->now()) {
            unset($this->locks[$accountId]);

            return false;
        }

        return true;
    }

    public function lock(string $accountId, LockoutReason $reason, ?int $until = null): void
    {
        $this->locks[$accountId] = ['reason' => $reason, 'until' => $until];
    }

    public function unlock(string $accountId): void
    {
        unset($this->locks[$accountId]);
    }
}
