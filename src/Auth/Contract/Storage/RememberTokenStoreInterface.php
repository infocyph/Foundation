<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Storage;

use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenRecord;

interface RememberTokenStoreInterface
{
    public function find(string $recordId): ?RememberTokenRecord;

    public function findBySelector(string $selector): ?RememberTokenRecord;

    public function markUsed(string $recordId, int $usedAt): void;

    public function revokeFamily(string $familyId): void;

    public function rotate(string $recordId, RememberTokenRecord $replacement): void;

    public function save(RememberTokenRecord $record): void;

    public function wasFamilyRevoked(string $familyId): bool;
}
