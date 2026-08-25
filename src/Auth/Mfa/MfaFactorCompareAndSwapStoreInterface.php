<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

interface MfaFactorCompareAndSwapStoreInterface extends MfaFactorStoreInterface
{
    /**
     * Atomically create or replace one factor using its persisted revision.
     * Creation is allowed only when no expected factor is supplied and the new
     * factor starts at revision zero. Replacement succeeds only when the stored
     * revision still matches the expected factor and the update advances it by
     * exactly one revision.
     *
     * @param MfaFactor|null $expected Persisted factor expected before the write.
     * @param MfaFactor $updated Replacement factor to persist.
     */
    public function compareAndSwap(?MfaFactor $expected, MfaFactor $updated): bool;
}
