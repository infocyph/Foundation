<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

abstract readonly class DBLayerFamilyTokenStore extends ClockedDBLayerStore
{
    protected function markTokenRotated(string $table, string $recordId): void
    {
        $this->updateWhere($table, ['rotated_at' => $this->now()], 'id = ?', [$recordId]);
    }

    protected function rotateTokenIfActive(
        string $table,
        string $tokenId,
        string $accountId,
        string $familyId,
        string $hashColumn,
        string $tokenHash,
        callable $saveReplacement,
    ): bool {
        return $this->connection()->transaction(function () use (
            $table,
            $tokenId,
            $accountId,
            $familyId,
            $hashColumn,
            $tokenHash,
            $saveReplacement,
        ): bool {
            $updated = $this->query($table)
                ->whereRaw(
                    sprintf('id = ? AND account_id = ? AND family_id = ? AND %s = ? AND rotated_at IS NULL AND revoked_at IS NULL AND expires_at > ?', $hashColumn),
                    [$tokenId, $accountId, $familyId, $tokenHash, $this->now()],
                )
                ->update(['rotated_at' => $this->now()]);
            if ($updated !== 1) {
                return false;
            }

            $saveReplacement();

            return true;
        }) === true;
    }

    protected function revokeTokenFamily(string $table, string $familyId): void
    {
        $this->updateWhere($table, ['revoked_at' => $this->now()], 'family_id = ? AND revoked_at IS NULL', [$familyId]);
    }

    protected function tokenFamilyWasRevoked(string $table, string $familyId): bool
    {
        return $this->first(
            sprintf('SELECT id FROM %s WHERE family_id = ? AND revoked_at IS NOT NULL', $this->table($table)),
            [$familyId],
        ) !== null;
    }
}
