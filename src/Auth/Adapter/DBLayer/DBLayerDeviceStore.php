<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Device\DeviceRecord;
use Infocyph\Foundation\Auth\Device\DeviceStoreInterface;

final readonly class DBLayerDeviceStore extends ClockedDBLayerStore implements DeviceStoreInterface
{
    public function find(string $deviceId): ?DeviceRecord
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('devices')),
            $this->mapDevice(...),
            [$deviceId],
        );
    }

    public function findForAccount(string $accountId): array
    {
        return $this->allMapped(
            sprintf('SELECT * FROM %s WHERE account_id = ?', $this->table('devices')),
            $this->mapDevice(...),
            [$accountId],
        );
    }

    public function markTrusted(string $deviceId, bool $trusted): void
    {
        $this->updateWhere('devices', ['trusted' => $trusted ? 1 : 0], 'id = ?', [$deviceId]);
    }

    public function revoke(string $deviceId): void
    {
        $this->updateWhere('devices', ['trusted' => 0, 'revoked_at' => $this->now()], 'id = ?', [$deviceId]);
    }

    public function save(DeviceRecord $device): void
    {
        $this->upsertRecord('devices', 'id', [
            'id' => $device->id,
            'account_id' => $device->accountId,
            'label' => $device->label,
            'fingerprint' => $device->fingerprint,
            'trusted' => $device->trusted ? 1 : 0,
            'created_at' => $device->createdAt,
            'last_seen_at' => $device->lastSeenAt,
            'revoked_at' => $device->revokedAt,
            'metadata' => DBLayerJson::encode($device->metadata),
        ]);
    }

    public function touch(string $deviceId, int $lastSeenAt): void
    {
        $this->updateWhere('devices', ['last_seen_at' => $lastSeenAt], 'id = ? AND revoked_at IS NULL', [$deviceId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapDevice(array $row): DeviceRecord
    {
        return new DeviceRecord(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            label: $this->stringOrNull($row['label'] ?? null),
            fingerprint: $this->stringOrNull($row['fingerprint'] ?? null),
            trusted: $this->truthy($row['trusted'] ?? false),
            createdAt: $this->int($row['created_at'] ?? 0),
            lastSeenAt: $this->intOrNull($row['last_seen_at'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }
}
