<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenRecord;
use Infocyph\Foundation\Auth\Contract\Storage\RefreshTokenStoreInterface;

final class InMemoryRefreshTokenStore extends AbstractInMemoryFamilyTokenStore implements RefreshTokenStoreInterface
{
    public function find(string $tokenId): ?RefreshTokenRecord
    {
        $record = $this->findStored($tokenId);

        if (!$record instanceof RefreshTokenRecord) {
            return null;
        }

        return $record;
    }

    public function revokeFamily(string $familyId): void
    {
        $revokedFamilyId = $familyId;
        $this->revokeStoredFamily($revokedFamilyId);
    }

    public function rotate(string $tokenId, RefreshTokenRecord $replacement): void
    {
        $replacementId = $replacement->id;
        $this->rotateStored($tokenId, $replacement, $replacementId);
    }

    public function save(RefreshTokenRecord $record): void
    {
        $tokenId = $record->id;
        $this->saveStored($record, $tokenId);
    }

    public function wasFamilyRevoked(string $familyId): bool
    {
        return $this->wasStoredFamilyRevoked($familyId);
    }

    protected function familyId(object $record): string
    {
        if (!$record instanceof RefreshTokenRecord) {
            return '';
        }

        return $record->familyId;
    }

    protected function revokeRecord(object $record, int $revokedAt): object
    {
        if (!$record instanceof RefreshTokenRecord) {
            return $record;
        }

        return $record->withRevokedAt($revokedAt);
    }

    protected function rotateRecord(object $record, int $rotatedAt): object
    {
        if (!$record instanceof RefreshTokenRecord) {
            return $record;
        }

        return $record->withRotatedAt($rotatedAt);
    }
}
