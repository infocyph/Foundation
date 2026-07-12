<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorCompareAndSwapStoreInterface;

final class InMemoryMfaFactorStore implements MfaFactorCompareAndSwapStoreInterface
{
    /**
     * @var array<string, MfaFactor>
     */
    private array $factors = [];

    public function compareAndSwap(MfaFactor $expected, MfaFactor $updated): bool
    {
        if (($this->factors[$expected->id] ?? null) != $expected || $updated->id !== $expected->id) {
            return false;
        }

        $this->factors[$updated->id] = $updated;

        return true;
    }

    public function findForAccount(string $accountId): array
    {
        return array_values(array_filter(
            $this->factors,
            static fn(MfaFactor $factor): bool => $factor->accountId === $accountId,
        ));
    }

    public function remove(string $factorId): void
    {
        unset($this->factors[$factorId]);
    }

    public function save(MfaFactor $factor): void
    {
        $this->factors[$factor->id] = $factor;
    }
}
