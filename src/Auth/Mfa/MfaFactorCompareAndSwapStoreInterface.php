<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

interface MfaFactorCompareAndSwapStoreInterface extends MfaFactorStoreInterface
{
    /**
     * Atomically create or update a factor.
     *
     * A null expected value means create only when the factor ID does not
     * already exist. A non-null expected value means replace only when the
     * persisted state still equals that exact expected factor.
     */
    public function compareAndSwap(?MfaFactor $expected, MfaFactor $updated): bool;
}
