<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process;

use Closure;

final readonly class ProcessOptions
{
    public ?Closure $cancelled;

    public ?Closure $heartbeat;

    public ?Closure $onStderr;

    public ?Closure $onStdout;

    /**
     * @param array<string, string> $environment
     * @param (callable():bool)|null $cancelled Return true to cancel the child.
     * @param (callable():bool)|null $heartbeat Return false when the child must stop.
     * @param (callable(string):void)|null $onStdout
     * @param (callable(string):void)|null $onStderr
     */
    public function __construct(
        public ?string $cwd = null,
        public array $environment = [],
        public ?float $timeoutSeconds = null,
        public ?string $input = null,
        public bool $interactive = false,
        public ?float $idleTimeoutSeconds = null,
        public ?int $maxOutputBytes = 16_777_216,
        public bool $captureOutput = true,
        public bool $passthrough = false,
        public bool $inheritInput = false,
        ?callable $cancelled = null,
        ?callable $heartbeat = null,
        ?callable $onStdout = null,
        ?callable $onStderr = null,
        public float $terminationGraceSeconds = 2.0,
        public bool $handleSignals = true,
    ) {
        $this->validateDurations();
        $this->validateOutputLimit();
        $this->validateInteractiveStreams($onStdout, $onStderr);

        $this->cancelled = $cancelled === null ? null : Closure::fromCallable($cancelled);
        $this->heartbeat = $heartbeat === null ? null : Closure::fromCallable($heartbeat);
        $this->onStdout = $onStdout === null ? null : Closure::fromCallable($onStdout);
        $this->onStderr = $onStderr === null ? null : Closure::fromCallable($onStderr);
    }

    private function validateDurations(): void
    {
        if ($this->timeoutSeconds !== null
            && (!is_finite($this->timeoutSeconds) || $this->timeoutSeconds <= 0.0)
        ) {
            throw new \InvalidArgumentException('Process timeout must be positive and finite.');
        }
        if ($this->idleTimeoutSeconds !== null
            && (!is_finite($this->idleTimeoutSeconds) || $this->idleTimeoutSeconds <= 0.0)
        ) {
            throw new \InvalidArgumentException('Process idle timeout must be positive and finite.');
        }
        if (!is_finite($this->terminationGraceSeconds) || $this->terminationGraceSeconds < 0.0) {
            throw new \InvalidArgumentException('Process termination grace must be finite and non-negative.');
        }
    }

    /**
     * @param (callable(string):void)|null $onStdout
     * @param (callable(string):void)|null $onStderr
     */
    private function validateInteractiveStreams(?callable $onStdout, ?callable $onStderr): void
    {
        if (!$this->interactive) {
            return;
        }
        if ($this->input === null
            && !$this->inheritInput
            && !$this->passthrough
            && $onStdout === null
            && $onStderr === null
        ) {
            return;
        }

        throw new \InvalidArgumentException(
            'Interactive mode owns STDIN/STDOUT/STDERR and cannot be combined with explicit stream options.',
        );
    }

    private function validateOutputLimit(): void
    {
        if ($this->maxOutputBytes !== null && $this->maxOutputBytes < 1) {
            throw new \InvalidArgumentException('Process output limit must be null or a positive byte count.');
        }
    }
}
