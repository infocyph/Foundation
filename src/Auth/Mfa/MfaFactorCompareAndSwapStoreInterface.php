<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

interface MfaFactorCompareAndSwapStoreInterface extends MfaFactorStoreInterface
{
    /**
     * Atomically create or replace one factor.
     *
     * A null expected value creates only when the factor ID is absent and the
     * new factor has revision zero. A non-null expected value replaces only
     * when the persisted factor still has the expected revision; the updated
     * factor must carry exactly the next revision.
     */
    public function compareAndSwap(?MfaFactor $expected, MfaFactor $updated): bool;
}
