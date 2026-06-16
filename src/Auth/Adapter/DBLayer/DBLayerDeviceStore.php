<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Device\DeviceRecord;
use Infocyph\Foundation\Auth\Device\DeviceStoreInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerDeviceStore extends DBLayerStore implements DeviceStoreInterface
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        private ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

    public function find(string $deviceId): ?DeviceRecord
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('devices')),
            [$deviceId],
        );

        return $row === null ? null : $this->mapDevice($row);
    }

    public function findForAccount(string $accountId): array
    {
        return array_map(
            fn(array $row): DeviceRecord => $this->mapDevice($row),
            $this->all(
                sprintf('SELECT * FROM %s WHERE account_id = ?', $this->table('devices')),
                [$accountId],
            ),
        );
    }

    public function markTrusted(string $deviceId, bool $trusted): void
    {
        $this->execute(
            sprintf('UPDATE %s SET trusted = ? WHERE id = ?', $this->table('devices')),
            [$trusted ? 1 : 0, $deviceId],
        );
    }

    public function revoke(string $deviceId): void
    {
        $this->execute(
            sprintf('UPDATE %s SET trusted = 0, revoked_at = ? WHERE id = ?', $this->table('devices')),
            [$this->clock->now(), $deviceId],
        );
    }

    public function save(DeviceRecord $device): void
    {
        if ($this->find($device->id) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET account_id = ?, label = ?, fingerprint = ?, trusted = ?, created_at = ?, last_seen_at = ?, revoked_at = ?, metadata = ? WHERE id = ?', $this->table('devices')),
                [
                    $device->accountId,
                    $device->label,
                    $device->fingerprint,
                    $device->trusted ? 1 : 0,
                    $device->createdAt,
                    $device->lastSeenAt,
                    $device->revokedAt,
                    DBLayerJson::encode($device->metadata),
                    $device->id,
                ],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, account_id, label, fingerprint, trusted, created_at, last_seen_at, revoked_at, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', $this->table('devices')),
            [
                $device->id,
                $device->accountId,
                $device->label,
                $device->fingerprint,
                $device->trusted ? 1 : 0,
                $device->createdAt,
                $device->lastSeenAt,
                $device->revokedAt,
                DBLayerJson::encode($device->metadata),
            ],
        );
    }

    public function touch(string $deviceId, int $lastSeenAt): void
    {
        $this->execute(
            sprintf('UPDATE %s SET last_seen_at = ? WHERE id = ? AND revoked_at IS NULL', $this->table('devices')),
            [$lastSeenAt, $deviceId],
        );
    }

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
