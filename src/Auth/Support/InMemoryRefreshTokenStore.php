<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenRecord;
use Infocyph\Foundation\Auth\Contract\Storage\RefreshTokenStoreInterface;

final class InMemoryRefreshTokenStore extends AbstractInMemoryFamilyTokenStore implements RefreshTokenStoreInterface
{
    #[\Override]
    public function find(string $tokenId): ?RefreshTokenRecord
    {
        $record = $this->findStored($tokenId);

        if (!$record instanceof RefreshTokenRecord) {
            return null;
        }

        return $record;
    }

    #[\Override]
    public function revokeFamily(string $familyId): void
    {
        $revokedFamilyId = $familyId;
        $this->revokeStoredFamily($revokedFamilyId);
    }

    #[\Override]
    public function rotate(string $tokenId, RefreshTokenRecord $replacement): void
    {
        $current = $this->find($tokenId);
        if ($current === null || !$this->rotateIfActive($current, $replacement)) {
            throw new \RuntimeException('Token cannot be rotated more than once.');
        }
    }

    #[\Override]
    public function rotateIfActive(RefreshTokenRecord $current, RefreshTokenRecord $replacement): bool
    {
        return $this->rotateStoredIfActive($current, $replacement);
    }

    #[\Override]
    public function save(RefreshTokenRecord $record): void
    {
        $tokenId = $record->id;
        $this->saveStored($record, $tokenId);
    }

    #[\Override]
    public function wasFamilyRevoked(string $familyId): bool
    {
        return $this->wasStoredFamilyRevoked($familyId);
    }

    #[\Override]
    protected function familyId(object $record): string
    {
        if (!$record instanceof RefreshTokenRecord) {
            return '';
        }

        return $record->familyId;
    }

    #[\Override]
    protected function revokeRecord(object $record, int $revokedAt): object
    {
        if (!$record instanceof RefreshTokenRecord) {
            return $record;
        }

        return $record->withRevokedAt($revokedAt);
    }

    #[\Override]
    protected function rotateRecord(object $record, int $rotatedAt): object
    {
        if (!$record instanceof RefreshTokenRecord) {
            return $record;
        }

        return $record->withRotatedAt($rotatedAt);
    }
}
