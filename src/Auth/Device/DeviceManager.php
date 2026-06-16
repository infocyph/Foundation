<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Device;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class DeviceManager
{
    public function __construct(
        private DeviceStoreInterface $devices,
        private AuthIdGeneratorInterface $ids,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @return list<DeviceRecord>
     */
    public function listForAccount(string $accountId): array
    {
        return $this->devices->findForAccount($accountId);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function register(string $accountId, ?string $label, ?string $fingerprint, array $metadata = []): DeviceResult
    {
        $device = new DeviceRecord(
            id: $this->ids->deviceId(),
            accountId: $accountId,
            label: $label,
            fingerprint: $fingerprint,
            trusted: false,
            createdAt: $this->clock->now(),
            metadata: $metadata,
        );

        $this->devices->save($device);

        return new DeviceResult(DeviceStatus::REGISTERED, device: $device, code: 'device_registered', context: $metadata);
    }

    public function revoke(string $deviceId): DeviceResult
    {
        $device = $this->devices->find($deviceId);

        if ($device === null) {
            return new DeviceResult(DeviceStatus::NOT_FOUND, code: 'device_not_found');
        }

        $this->devices->revoke($deviceId);

        return new DeviceResult(DeviceStatus::REVOKED, device: $device, code: 'device_revoked');
    }

    public function touch(string $deviceId, ?int $seenAt = null): DeviceResult
    {
        $device = $this->devices->find($deviceId);

        if ($device === null) {
            return new DeviceResult(DeviceStatus::NOT_FOUND, code: 'device_not_found');
        }

        $this->devices->touch($deviceId, $seenAt ?? $this->clock->now());

        return new DeviceResult(DeviceStatus::TOUCHED, device: $this->devices->find($deviceId), code: 'device_touched');
    }

    public function trust(string $deviceId): DeviceResult
    {
        $device = $this->devices->find($deviceId);

        if ($device === null) {
            return new DeviceResult(DeviceStatus::NOT_FOUND, code: 'device_not_found');
        }

        $this->devices->markTrusted($deviceId, true);

        return new DeviceResult(DeviceStatus::TRUSTED, device: $this->devices->find($deviceId), code: 'device_trusted');
    }
}
