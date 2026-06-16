<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

interface MfaFactorStoreInterface
{
    /**
     * @return list<MfaFactor>
     */
    public function findForAccount(string $accountId): array;

    public function remove(string $factorId): void;

    public function save(MfaFactor $factor): void;
}
