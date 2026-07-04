<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Device;

use Infocyph\Foundation\Auth\Support\TracksSuccessfulStatus;

final readonly class DeviceResult
{
    use TracksSuccessfulStatus;

    /**
     * @param list<DeviceRecord> $devices
     * @param array<string, mixed> $context
     */
    public function __construct(
        public DeviceStatus $status,
        public ?DeviceRecord $device = null,
        public array $devices = [],
        public ?string $code = null,
        public array $context = [],
    ) {}

    /**
     * @return list<\UnitEnum>
     */
    protected function successfulStatuses(): array
    {
        return [
            DeviceStatus::REGISTERED,
            DeviceStatus::TRUSTED,
            DeviceStatus::TOUCHED,
            DeviceStatus::REVOKED,
        ];
    }
}
