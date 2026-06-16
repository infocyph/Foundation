<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Device;

interface DeviceStoreInterface
{
    public function find(string $deviceId): ?DeviceRecord;

    /**
     * @return list<DeviceRecord>
     */
    public function findForAccount(string $accountId): array;

    public function markTrusted(string $deviceId, bool $trusted): void;

    public function revoke(string $deviceId): void;

    public function save(DeviceRecord $device): void;

    public function touch(string $deviceId, int $lastSeenAt): void;
}
