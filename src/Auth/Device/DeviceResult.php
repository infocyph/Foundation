<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Device;

final readonly class DeviceResult
{
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

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === DeviceStatus::REGISTERED
            || $this->status === DeviceStatus::TRUSTED
            || $this->status === DeviceStatus::TOUCHED
            || $this->status === DeviceStatus::REVOKED;
    }
}
