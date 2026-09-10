<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Adapter\Epicrypt\MfaSecretProtector;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorCompareAndSwapStoreInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerMfaFactorStore extends DBLayerStore implements MfaFactorCompareAndSwapStoreInterface
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        ?string $connection = null,
        private ?MfaSecretProtector $secretProtector = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

    public function compareAndSwap(?MfaFactor $expected, MfaFactor $updated): bool
    {
        if ($expected === null) {
            if ($updated->revision !== 0) {
                return false;
            }

            try {
                $this->insertRecord('mfaFactors', $this->record($updated));

                return true;
            } catch (\Throwable $failure) {
                if ($this->query('mfaFactors')->where('id', '=', $updated->id)->exists()) {
                    return false;
                }

                throw $failure;
            }
        }

        if ($updated->id !== $expected->id || $updated->revision !== $expected->revision + 1) {
            return false;
        }

        $record = $this->record($updated);
        unset($record['id']);

        return $this->query('mfaFactors')
            ->where('id', '=', $expected->id)
            ->where('revision', '=', $expected->revision)
            ->update($record) === 1;
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
        $this->deleteWhere('mfaFactors', 'id = ?', [$factorId]);
    }

    public function save(MfaFactor $factor): void
    {
        $this->upsertRecord('mfaFactors', 'id', $this->record($factor));
    }

    /** @param array<string, mixed> $row */
    private function mapFactor(array $row): MfaFactor
    {
        $factor = new MfaFactor(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            type: $this->string($row['type'] ?? ''),
            label: $this->string($row['label'] ?? ''),
            enabled: $this->truthy($row['enabled'] ?? false),
            createdAt: $this->int($row['created_at'] ?? 0),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
            revision: $this->int($row['revision'] ?? 0),
        );

        return $this->secretProtector?->unprotect($factor) ?? $factor;
    }

    /** @return array<string, mixed> */
    private function record(MfaFactor $factor): array
    {
        $stored = $this->secretProtector?->protect($factor) ?? $factor;

        return [
            'id' => $stored->id,
            'account_id' => $stored->accountId,
            'type' => $stored->type,
            'label' => $stored->label,
            'enabled' => $stored->enabled ? 1 : 0,
            'created_at' => $stored->createdAt,
            'metadata' => DBLayerJson::encode($stored->metadata),
            'revision' => $stored->revision,
        ];
    }
}
