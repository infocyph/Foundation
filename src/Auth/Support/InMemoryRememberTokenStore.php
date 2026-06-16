<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenRecord;
use Infocyph\Foundation\Auth\Contract\Storage\RememberTokenStoreInterface;

final class InMemoryRememberTokenStore extends AbstractInMemoryFamilyTokenStore implements RememberTokenStoreInterface
{
    public function find(string $recordId): ?RememberTokenRecord
    {
        $record = $this->findStored($recordId);

        return $record instanceof RememberTokenRecord ? $record : null;
    }

    public function findBySelector(string $selector): ?RememberTokenRecord
    {
        foreach ($this->records as $record) {
            if ($record instanceof RememberTokenRecord && $record->selector === $selector) {
                return $record;
            }
        }

        return null;
    }

    public function markUsed(string $recordId, int $usedAt): void
    {
        $record = $this->find($recordId);

        if ($record === null || $record->isRevoked()) {
            return;
        }

        $this->saveStored($record->withLastUsedAt($usedAt), $recordId);
    }

    public function revokeFamily(string $familyId): void
    {
        $this->revokeStoredFamily($familyId);
    }

    public function rotate(string $recordId, RememberTokenRecord $replacement): void
    {
        $this->rotateStored($recordId, $replacement, $replacement->id);
    }

    public function save(RememberTokenRecord $record): void
    {
        $this->saveStored($record, $record->id);
    }

    public function wasFamilyRevoked(string $familyId): bool
    {
        return $this->wasStoredFamilyRevoked($familyId);
    }

    protected function familyId(object $record): string
    {
        return $record instanceof RememberTokenRecord ? $record->familyId : '';
    }

    protected function revokeRecord(object $record, int $revokedAt): object
    {
        return $record instanceof RememberTokenRecord ? $record->withRevokedAt($revokedAt) : $record;
    }

    protected function rotateRecord(object $record, int $rotatedAt): object
    {
        return $record instanceof RememberTokenRecord ? $record->withRotatedAt($rotatedAt) : $record;
    }
}
