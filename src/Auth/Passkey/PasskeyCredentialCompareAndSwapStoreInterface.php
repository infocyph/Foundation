<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

interface PasskeyCredentialCompareAndSwapStoreInterface extends PasskeyCredentialStoreInterface
{
    /**
     * Atomically create or replace one passkey credential using its persisted revision.
     *
     * Creation is allowed only with no expected credential and revision zero.
     * Replacement succeeds only when the stored revision still matches the
     * expected credential and the update advances it by exactly one revision.
     */
    public function compareAndSwap(?PasskeyCredential $expected, PasskeyCredential $updated): bool;
}
