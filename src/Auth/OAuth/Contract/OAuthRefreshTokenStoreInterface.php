<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Contract;

use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshRotationResult;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenRecord;

/** @deprecated Foundation 3 runtime uses Epicrypt RefreshTokenStoreInterface. */
interface OAuthRefreshTokenStoreInterface
{
    public function findByHash(string $tokenHash): ?OAuthRefreshTokenRecord;

    public function revokeFamily(string $familyId, int $revokedAt): void;

    public function rotate(
        string $tokenHash,
        OAuthRefreshTokenRecord $replacement,
        int $rotatedAt,
    ): OAuthRefreshRotationResult;

    public function save(OAuthRefreshTokenRecord $record): void;
}
