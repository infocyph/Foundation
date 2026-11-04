<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenRecord;
use Infocyph\Foundation\Auth\Contract\Storage\RememberTokenStoreInterface;

final class InMemoryRememberTokenStore extends AbstractInMemoryFamilyTokenStore implements RememberTokenStoreInterface
{
    #[\Override]
    public function find(string $recordId): ?RememberTokenRecord
    {
        $record = $this->findStored($recordId);

        return $record instanceof RememberTokenRecord ? $record : null;
    }

    #[\Override]
    public function findBySelector(string $selector): ?RememberTokenRecord
    {
        foreach ($this->records as $record) {
            if ($record instanceof RememberTokenRecord && $record->selector === $selector) {
                return $record;
            }
        }

        return null;
    }

    #[\Override]
    public function markUsed(string $recordId, int $usedAt): void
    {
        $record = $this->find($recordId);

        if ($record === null || $record->isRevoked()) {
            return;
        }

        $this->saveStored($record->withLastUsedAt($usedAt), $recordId);
    }

    #[\Override]
    public function revokeFamily(string $familyId): void
    {
        $this->revokeStoredFamily($familyId);
    }

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function rotate(string $recordId, RememberTokenRecord $replacement): void
    {
        $current = $this->find($recordId);
        if ($current === null || !$this->rotateIfActive($current, $replacement)) {
            throw new \RuntimeException('Token cannot be rotated more than once.');
        }
    }

    #[\Override]
    public function rotateIfActive(RememberTokenRecord $current, RememberTokenRecord $replacement): bool
    {
        return $this->rotateStoredIfActive($current, $replacement);
    }

    #[\Override]
    public function save(RememberTokenRecord $record): void
    {
        $this->saveStored($record, $record->id);
    }

    #[\Override]
    public function wasFamilyRevoked(string $familyId): bool
    {
        return $this->wasStoredFamilyRevoked($familyId);
    }

    #[\Override]
    protected function familyId(object $record): string
    {
        return $record instanceof RememberTokenRecord ? $record->familyId : '';
    }

    #[\Override]
    protected function revokeRecord(object $record, int $revokedAt): object
    {
        return $record instanceof RememberTokenRecord ? $record->withRevokedAt($revokedAt) : $record;
    }

    #[\Override]
    protected function rotateRecord(object $record, int $rotatedAt): object
    {
        return $record instanceof RememberTokenRecord ? $record->withRotatedAt($rotatedAt) : $record;
    }
}
