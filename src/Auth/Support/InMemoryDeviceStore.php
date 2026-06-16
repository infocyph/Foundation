<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Device\DeviceRecord;
use Infocyph\Foundation\Auth\Device\DeviceStoreInterface;

final class InMemoryDeviceStore implements DeviceStoreInterface
{
    /**
     * @var array<string, DeviceRecord>
     */
    private array $devices = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function find(string $deviceId): ?DeviceRecord
    {
        return $this->devices[$deviceId] ?? null;
    }

    public function findForAccount(string $accountId): array
    {
        return array_values(array_filter(
            $this->devices,
            static fn(DeviceRecord $device): bool => $device->accountId === $accountId,
        ));
    }

    public function markTrusted(string $deviceId, bool $trusted): void
    {
        $device = $this->devices[$deviceId] ?? null;

        if ($device === null) {
            return;
        }

        $this->devices[$deviceId] = $trusted
            ? $device->trusted()
            : new DeviceRecord(
                id: $device->id,
                accountId: $device->accountId,
                label: $device->label,
                fingerprint: $device->fingerprint,
                trusted: false,
                createdAt: $device->createdAt,
                lastSeenAt: $device->lastSeenAt,
                revokedAt: $device->revokedAt,
                metadata: $device->metadata,
            );
    }

    public function revoke(string $deviceId): void
    {
        $device = $this->devices[$deviceId] ?? null;

        if ($device === null) {
            return;
        }

        $this->devices[$deviceId] = $device->revokedAt($this->clock->now());
    }

    public function save(DeviceRecord $device): void
    {
        $this->devices[$device->id] = $device;
    }

    public function touch(string $deviceId, int $lastSeenAt): void
    {
        $device = $this->devices[$deviceId] ?? null;

        if ($device === null || $device->isRevoked()) {
            return;
        }

        $this->devices[$deviceId] = $device->seenAt($lastSeenAt);
    }
}
