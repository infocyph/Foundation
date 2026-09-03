<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Operations;

use Infocyph\Webrick\Middleware\Maintenance\MaintenanceStateInterface;

/** Worker-local bounded view of the Foundation maintenance control plane. */
final class MaintenanceRuntimeState implements MaintenanceStateInterface
{
    /** @var array{enabled:bool,enabled_at:?string,retry_after:?int,message:?string,driver:string}|null */
    private ?array $cached = null;

    private int $nextRefreshNs = 0;

    public function __construct(
        private readonly MaintenanceManager $manager,
        private readonly int $refreshMilliseconds = 1000,
        private readonly int $defaultRetryAfter = 3600,
    ) {
        if ($this->refreshMilliseconds < 0) {
            throw new \InvalidArgumentException('Maintenance refresh interval must be >= 0.');
        }
        if ($this->defaultRetryAfter < 0) {
            throw new \InvalidArgumentException('Maintenance Retry-After must be >= 0.');
        }
    }

    public function message(): ?string
    {
        $status = $this->status();
        if (!$status['enabled']) {
            return null;
        }

        return $status['message'] ?? 'Service temporarily unavailable for maintenance.';
    }

    public function retryAfter(): int
    {
        return $this->status()['retry_after'] ?? $this->defaultRetryAfter;
    }

    /** @return array{enabled:bool,enabled_at:?string,retry_after:?int,message:?string,driver:string} */
    public function status(): array
    {
        $now = hrtime(true);
        if ($this->cached !== null && $now < $this->nextRefreshNs) {
            return $this->cached;
        }

        $this->nextRefreshNs = $now + ($this->refreshMilliseconds * 1_000_000);

        return $this->cached = $this->manager->status();
    }
}
