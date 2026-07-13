<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

abstract readonly class DBLayerFamilyTokenStore extends ClockedDBLayerStore
{
    protected function markTokenRotated(string $table, string $recordId): void
    {
        $this->updateWhere($table, ['rotated_at' => $this->now()], 'id = ?', [$recordId]);
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
