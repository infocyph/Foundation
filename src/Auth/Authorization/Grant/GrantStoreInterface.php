<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Grant;

interface GrantStoreInterface
{
    /**
     * @return list<AccessGrant>
     */
    public function grantsForPrincipal(string $principalId): array;

    public function revoke(string $grantId): void;

    public function save(AccessGrant $grant): void;
}
