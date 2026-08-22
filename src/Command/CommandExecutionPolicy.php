<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final readonly class CommandExecutionPolicy
{
    public function __construct(
        public bool $isolated = false,
        public OverlapMode $overlap = OverlapMode::Allow,
        public ?string $mutex = null,
        public float $waitSeconds = 0.0,
        public float $leaseSeconds = 30.0,
        public ?float $timeoutSeconds = null,
        public ?float $idleTimeoutSeconds = null,
        public ?int $memoryLimitMegabytes = null,
        public float $terminationGraceSeconds = 2.0,
    ) {
        if ($mutex !== null && trim($mutex) === '') {
            throw new \InvalidArgumentException('Command mutex name cannot be empty.');
        }
        if (!is_finite($waitSeconds) || $waitSeconds < 0.0) {
            throw new \InvalidArgumentException('Command overlap wait must be finite and non-negative.');
        }
        if (!is_finite($leaseSeconds) || $leaseSeconds <= 0.0) {
            throw new \InvalidArgumentException('Command lock lease must be positive and finite.');
        }
        foreach (['timeout' => $timeoutSeconds, 'idle timeout' => $idleTimeoutSeconds] as $label => $seconds) {
            if ($seconds !== null && (!is_finite($seconds) || $seconds <= 0.0)) {
                throw new \InvalidArgumentException(sprintf('Command %s must be positive and finite.', $label));
            }
        }
        if ($memoryLimitMegabytes !== null && $memoryLimitMegabytes < 1) {
            throw new \InvalidArgumentException('Command memory limit must be a positive MiB value.');
        }
        if (!is_finite($terminationGraceSeconds) || $terminationGraceSeconds < 0.0) {
            throw new \InvalidArgumentException('Command termination grace must be finite and non-negative.');
        }
    }

    public function requiresSupervisor(): bool
    {
        return $this->isolated
            || $this->overlap !== OverlapMode::Allow
            || $this->timeoutSeconds !== null
            || $this->idleTimeoutSeconds !== null
            || $this->memoryLimitMegabytes !== null;
    }

    /** @return array<string, mixed> */
    public function toManifest(): array
    {
        return [
            'isolated' => $this->isolated,
            'overlap' => $this->overlap->value,
            'mutex' => $this->mutex,
            'wait_seconds' => $this->waitSeconds,
            'lease_seconds' => $this->leaseSeconds,
            'timeout_seconds' => $this->timeoutSeconds,
            'idle_timeout_seconds' => $this->idleTimeoutSeconds,
            'memory_limit_megabytes' => $this->memoryLimitMegabytes,
            'termination_grace_seconds' => $this->terminationGraceSeconds,
        ];
    }

    /** @param array<string, mixed> $manifest */
    public static function fromManifest(array $manifest): self
    {
        $isolated = $manifest['isolated'] ?? false;
        $overlap = $manifest['overlap'] ?? OverlapMode::Allow->value;
        $mutex = $manifest['mutex'] ?? null;
        $wait = $manifest['wait_seconds'] ?? 0.0;
        $lease = $manifest['lease_seconds'] ?? 30.0;
        $timeout = $manifest['timeout_seconds'] ?? null;
        $idleTimeout = $manifest['idle_timeout_seconds'] ?? null;
        $memory = $manifest['memory_limit_megabytes'] ?? null;
        $grace = $manifest['termination_grace_seconds'] ?? 2.0;

        if (!is_bool($isolated)
            || !is_string($overlap)
            || !(is_string($mutex) || $mutex === null)
            || !is_numeric($wait)
            || !is_numeric($lease)
            || !(is_numeric($timeout) || $timeout === null)
            || !(is_numeric($idleTimeout) || $idleTimeout === null)
            || !(is_int($memory) || $memory === null)
            || !is_numeric($grace)
        ) {
            throw new \UnexpectedValueException('Compiled command execution policy is invalid.');
        }

        try {
            $mode = OverlapMode::from($overlap);
        } catch (\ValueError $exception) {
            throw new \UnexpectedValueException(sprintf('Unknown command overlap mode "%s".', $overlap), previous: $exception);
        }

        return new self(
            isolated: $isolated,
            overlap: $mode,
            mutex: $mutex,
            waitSeconds: (float) $wait,
            leaseSeconds: (float) $lease,
            timeoutSeconds: $timeout === null ? null : (float) $timeout,
            idleTimeoutSeconds: $idleTimeout === null ? null : (float) $idleTimeout,
            memoryLimitMegabytes: $memory,
            terminationGraceSeconds: (float) $grace,
        );
    }
}
