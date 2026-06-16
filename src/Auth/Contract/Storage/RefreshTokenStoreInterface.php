<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Storage;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenRecord;

interface RefreshTokenStoreInterface
{
    public function find(string $tokenId): ?RefreshTokenRecord;

    public function revokeFamily(string $familyId): void;

    public function rotate(string $tokenId, RefreshTokenRecord $replacement): void;

    public function save(RefreshTokenRecord $record): void;

    public function wasFamilyRevoked(string $familyId): bool;
}
