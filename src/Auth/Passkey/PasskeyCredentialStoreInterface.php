<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

interface PasskeyCredentialStoreInterface
{
    public function findByCredentialId(string $credentialId): ?PasskeyCredential;

    /**
     * @return list<PasskeyCredential>
     */
    public function findForAccount(string $accountId): array;

    public function revoke(string $credentialId): void;

    public function save(PasskeyCredential $credential): void;

    public function updateUsage(string $credentialId, int $signCount, int $usedAt): void;
}
