<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

final class ScheduledCommand
{
    /** @var list<string> */
    private array $arguments = [];

    private CronExpression $cron;

    private ?string $key = null;

    private ?int $memoryLimitMegabytes = null;

    private bool $onOneServer = false;

    private float $overlapLeaseSeconds = 300.0;

    private float $overlapWaitSeconds = 0.0;

    private ?float $timeoutSeconds = null;

    private \DateTimeZone $timezone;

    private bool $withoutOverlap = false;

    public function __construct(private readonly string $command)
    {
        if ($command === '') {
            throw new \InvalidArgumentException('Scheduled command cannot be empty.');
        }
        $this->cron = new CronExpression('* * * * *');
        $this->timezone = new \DateTimeZone(date_default_timezone_get());
    }

    /** @param array<string, mixed> $data */
    public static function fromManifest(array $data): self
    {
        $command = new self(self::stringValue($data['command'] ?? null, ''));
        $command->arguments(self::stringList($data['arguments'] ?? null));
        $command->cron(self::stringValue($data['cron'] ?? null, '* * * * *'));
        $command->timezone(self::stringValue($data['timezone'] ?? null, date_default_timezone_get()));

        $key = self::nullableString($data['key'] ?? null);
        if ($key !== null) {
            $command->key($key);
        }

        $lease = self::floatValue($data['overlap_lease_seconds'] ?? null, 300.0);
        $wait = self::floatValue($data['overlap_wait_seconds'] ?? null, 0.0);
        if (($data['without_overlap'] ?? false) === true) {
            $command->withoutOverlap(true, $lease, $wait);
        }
        if (($data['on_one_server'] ?? false) === true) {
            $command->onOneServer(true, $lease, $wait);
        }

        $timeout = self::nullableFloat($data['timeout_seconds'] ?? null);
        if ($timeout !== null) {
            $command->timeout($timeout);
        }
        $memory = self::nullableInt($data['memory_limit_megabytes'] ?? null);
        if ($memory !== null) {
            $command->memoryLimit($memory);
        }

        return $command;
    }

    /** @param list<string> $arguments */
    public function arguments(array $arguments): self
    {
        if (array_any($arguments, static fn(string $argument): bool => $argument === '')) {
            throw new \InvalidArgumentException('Scheduled command arguments must be non-empty strings.');
        }
        $this->arguments = $arguments;

        return $this;
    }

    public function command(): string
    {
        return $this->command;
    }

    /** @return list<string> */
    public function commandArguments(): array
    {
        return $this->arguments;
    }

    public function cron(string $expression): self
    {
        $this->cron = new CronExpression($expression);

        return $this;
    }

    public function dailyAt(string $time): self
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new \InvalidArgumentException('Daily schedule times must use HH:MM format.');
        }
        [$hour, $minute] = explode(':', $time);

        return $this->cron((int) $minute . ' ' . (int) $hour . ' * * *');
    }

    public function due(\DateTimeInterface $now): bool
    {
        return $this->cron->matches(new \DateTimeImmutable('@' . $now->getTimestamp())->setTimezone($this->timezone));
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function identity(): string
    {
        return $this->key ?? hash('sha256', json_encode([
            $this->command,
            $this->arguments,
            $this->cron->expression(),
            $this->timezone->getName(),
        ], JSON_THROW_ON_ERROR));
    }

    public function key(string $key): self
    {
        if ($key === '' || strlen($key) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Schedule keys must be safe identifiers of at most 128 bytes.');
        }
        $this->key = $key;

        return $this;
    }

    public function memoryLimit(int $megabytes): self
    {
        if ($megabytes < 1) {
            throw new \InvalidArgumentException('Schedule memory limit must be positive.');
        }
        $this->memoryLimitMegabytes = $megabytes;

        return $this;
    }

    public function memoryLimitMegabytes(): ?int
    {
        return $this->memoryLimitMegabytes;
    }

    public function onOneServer(bool $enabled = true, float $leaseSeconds = 300.0, float $waitSeconds = 0.0): self
    {
        $this->assertLockTiming($leaseSeconds, $waitSeconds);
        $this->onOneServer = $enabled;
        $this->overlapLeaseSeconds = $leaseSeconds;
        $this->overlapWaitSeconds = $waitSeconds;

        return $this;
    }

    public function overlapLeaseSeconds(): float
    {
        return $this->overlapLeaseSeconds;
    }

    public function overlapWaitSeconds(): float
    {
        return $this->overlapWaitSeconds;
    }

    public function preventsOverlap(): bool
    {
        return $this->withoutOverlap;
    }

    public function requiresSingleServer(): bool
    {
        return $this->onOneServer;
    }

    public function timeout(float $seconds): self
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new \InvalidArgumentException('Schedule timeout must be positive.');
        }
        $this->timeoutSeconds = $seconds;

        return $this;
    }

    public function timeoutSeconds(): ?float
    {
        return $this->timeoutSeconds;
    }

    public function timezone(string|\DateTimeZone $timezone): self
    {
        $this->timezone = is_string($timezone) ? new \DateTimeZone($timezone) : $timezone;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toManifest(): array
    {
        return [
            'key' => $this->key,
            'command' => $this->command,
            'arguments' => $this->arguments,
            'cron' => $this->cron->expression(),
            'timezone' => $this->timezone->getName(),
            'without_overlap' => $this->withoutOverlap,
            'on_one_server' => $this->onOneServer,
            'overlap_wait_seconds' => $this->overlapWaitSeconds,
            'overlap_lease_seconds' => $this->overlapLeaseSeconds,
            'timeout_seconds' => $this->timeoutSeconds,
            'memory_limit_megabytes' => $this->memoryLimitMegabytes,
        ];
    }

    public function withoutOverlap(bool $enabled = true, float $leaseSeconds = 300.0, float $waitSeconds = 0.0): self
    {
        $this->assertLockTiming($leaseSeconds, $waitSeconds);
        $this->withoutOverlap = $enabled;
        $this->overlapLeaseSeconds = $leaseSeconds;
        $this->overlapWaitSeconds = $waitSeconds;

        return $this;
    }

    private static function floatValue(mixed $value, float $default): float
    {
        return self::nullableFloat($value) ?? $default;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $list = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \UnexpectedValueException('Schedule manifest arguments must be non-empty strings.');
            }
            $list[] = $entry;
        }

        return $list;
    }

    private static function stringValue(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    private function assertLockTiming(float $leaseSeconds, float $waitSeconds): void
    {
        if (!is_finite($leaseSeconds) || !is_finite($waitSeconds) || $leaseSeconds <= 0 || $waitSeconds < 0) {
            throw new \InvalidArgumentException('Schedule lock lease must be positive and wait cannot be negative.');
        }
    }
}
