<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

interface MfaFactorCompareAndSwapStoreInterface extends MfaFactorStoreInterface
{
    public function compareAndSwap(MfaFactor $expected, MfaFactor $updated): bool;
}
