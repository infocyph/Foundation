<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorCompareAndSwapStoreInterface;

final readonly class DBLayerMfaFactorStore extends DBLayerStore implements MfaFactorCompareAndSwapStoreInterface
{
    public function compareAndSwap(?MfaFactor $expected, MfaFactor $updated): bool
    {
        if ($expected === null) {
            if ($updated->revision !== 0) {
                return false;
            }

            try {
                $this->execute(
                    sprintf('INSERT INTO %s (id, account_id, type, label, enabled, created_at, metadata, revision) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $this->table('mfaFactors')),
                    [
                        $updated->id,
                        $updated->accountId,
                        $updated->type,
                        $updated->label,
                        $updated->enabled ? 1 : 0,
                        $updated->createdAt,
                        DBLayerJson::encode($updated->metadata),
                        $updated->revision,
                    ],
                );

                return true;
            } catch (\Throwable $failure) {
                if ($this->first(
                    sprintf('SELECT id FROM %s WHERE id = ?', $this->table('mfaFactors')),
                    [$updated->id],
                ) !== null) {
                    return false;
                }

                throw $failure;
            }
        }

        if ($updated->id !== $expected->id || $updated->revision !== $expected->revision + 1) {
            return false;
        }

        return $this->connection()->update(
            sprintf(
                'UPDATE %s SET account_id = ?, type = ?, label = ?, enabled = ?, created_at = ?, metadata = ?, revision = ? '
                . 'WHERE id = ? AND revision = ?',
                $this->table('mfaFactors'),
            ),
            [
                $updated->accountId,
                $updated->type,
                $updated->label,
                $updated->enabled ? 1 : 0,
                $updated->createdAt,
                DBLayerJson::encode($updated->metadata),
                $updated->revision,
                $expected->id,
                $expected->revision,
            ],
        ) === 1;
    }

    public function findForAccount(string $accountId): array
    {
        return array_map(
            $this->mapFactor(...),
            $this->all(
                sprintf('SELECT * FROM %s WHERE account_id = ?', $this->table('mfaFactors')),
                [$accountId],
            ),
        );
    }

    public function remove(string $factorId): void
    {
        $this->execute(
            sprintf('DELETE FROM %s WHERE id = ?', $this->table('mfaFactors')),
            [$factorId],
        );
    }

    public function save(MfaFactor $factor): void
    {
        if ($this->first(
            sprintf('SELECT id FROM %s WHERE id = ?', $this->table('mfaFactors')),
            [$factor->id],
        ) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET account_id = ?, type = ?, label = ?, enabled = ?, created_at = ?, metadata = ?, revision = ? WHERE id = ?', $this->table('mfaFactors')),
                [
                    $factor->accountId,
                    $factor->type,
                    $factor->label,
                    $factor->enabled ? 1 : 0,
                    $factor->createdAt,
                    DBLayerJson::encode($factor->metadata),
                    $factor->revision,
                    $factor->id,
                ],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, account_id, type, label, enabled, created_at, metadata, revision) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $this->table('mfaFactors')),
            [
                $factor->id,
                $factor->accountId,
                $factor->type,
                $factor->label,
                $factor->enabled ? 1 : 0,
                $factor->createdAt,
                DBLayerJson::encode($factor->metadata),
                $factor->revision,
            ],
        );
    }

    /** @param array<string, mixed> $row */
    private function mapFactor(array $row): MfaFactor
    {
        return new MfaFactor(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            type: $this->string($row['type'] ?? ''),
            label: $this->string($row['label'] ?? ''),
            enabled: $this->truthy($row['enabled'] ?? false),
            createdAt: $this->int($row['created_at'] ?? 0),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
            revision: $this->int($row['revision'] ?? 0),
        );
    }
}
