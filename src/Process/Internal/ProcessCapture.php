<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process\Internal;

use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessResult;
use Infocyph\Foundation\Process\ProcessTerminationReason;

final readonly class ProcessCapture
{
    public function __construct(
        private ProcessTree $tree,
        private ProcessSignals $signals,
    ) {}

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     * @param array{pid:?int,group:bool} $tree
     */
    public function run($process, array $pipes, ProcessOptions $options, array $tree): ProcessResult
    {
        $startedAt = ProcessOutcome::clock();
        $input = $options->input ?? '';
        $inputOffset = 0;

        $stdout = '';
        $stderr = '';
        $observedBytes = 0;
        $lastActivityAt = $startedAt;
        $reason = null;
        $interruptedSignal = null;
        $signalState = null;

        try {
            $this->preparePipes($pipes, $options);
            $signalState = $this->signals->register(
                $options,
                static function (int $signal) use (&$interruptedSignal): void {
                    $interruptedSignal = $signal;
                },
            );

            while (($status = proc_get_status($process))['running']) {
                $reason = $this->requestedTermination(
                    $options,
                    $startedAt,
                    $lastActivityAt,
                    $interruptedSignal,
                );
                if ($reason !== null) {
                    break;
                }

                $reason = $this->poll(
                    $pipes,
                    $options,
                    $stdout,
                    $stderr,
                    $observedBytes,
                    $lastActivityAt,
                    $interruptedSignal,
                    $input,
                    $inputOffset,
                );
                if ($reason !== null) {
                    break;
                }
            }

            if ($reason !== null) {
                $this->tree->terminate($process, $options->terminationGraceSeconds, $tree);
            }

            $overflowed = $this->drain($pipes, $stdout, $stderr, $observedBytes, $options);
            if ($reason === null && $overflowed) {
                $reason = ProcessTerminationReason::OutputLimit;
            }
            $status = proc_get_status($process);
        } finally {
            $this->signals->restore($signalState);
            $this->closePipes($pipes);
        }

        $closedExit = proc_close($process);
        $reason ??= ProcessTerminationReason::Exited;
        $signal = ProcessOutcome::signal($status, $interruptedSignal);

        return new ProcessResult(
            exitCode: ProcessOutcome::exitCode($reason, $status, $closedExit, $signal),
            stdout: $stdout,
            stderr: $stderr,
            timedOut: in_array(
                $reason,
                [ProcessTerminationReason::TimedOut, ProcessTerminationReason::IdleTimedOut],
                true,
            ),
            reason: $reason,
            signal: $signal,
            durationNanoseconds: max(0, ProcessOutcome::clock() - $startedAt),
        );
    }

    private function allowedChunk(string $chunk, int $observedBytes, ?int $limit): string
    {
        if ($limit === null) {
            return $chunk;
        }

        $remaining = $limit - $observedBytes;
        if ($remaining <= 0) {
            return '';
        }

        return strlen($chunk) <= $remaining ? $chunk : substr($chunk, 0, $remaining);
    }

    /** @param array<int, mixed> $pipes Closed pipe handles may no longer be resources. */
    private function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /**
     * @param array<array-key, resource> $read
     * @param array<int, resource> $pipes
     */
    private function consumeReady(
        array $read,
        array $pipes,
        ProcessOptions $options,
        string &$stdout,
        string &$stderr,
        int &$observedBytes,
        int &$lastActivityAt,
    ): ?ProcessTerminationReason {
        foreach ($read as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                return ProcessTerminationReason::IoError;
            }
            if ($chunk === '') {
                continue;
            }

            $lastActivityAt = ProcessOutcome::clock();
            $index = $stream === ($pipes[1] ?? null) ? 1 : 2;
            $allowed = $this->allowedChunk($chunk, $observedBytes, $options->maxOutputBytes);
            $this->emit($index, $allowed, $stdout, $stderr, $options);
            $observedBytes += strlen($allowed);
            if ($options->maxOutputBytes !== null && strlen($allowed) < strlen($chunk)) {
                return ProcessTerminationReason::OutputLimit;
            }
        }

        return null;
    }

    private function deadlineReached(int $startedAt, ?float $seconds, int $now): bool
    {
        return $seconds !== null && ($now - $startedAt) / 1_000_000_000 >= $seconds;
    }

    /**
     * @param array<int, resource> $pipes
     */
    private function drain(
        array $pipes,
        string &$stdout,
        string &$stderr,
        int &$observedBytes,
        ProcessOptions $options,
    ): bool {
        $overflowed = false;
        foreach ([1, 2] as $index) {
            $stream = $pipes[$index] ?? null;
            if (!is_resource($stream)) {
                continue;
            }
            $chunk = stream_get_contents($stream);
            if (!is_string($chunk) || $chunk === '') {
                continue;
            }
            $allowed = $this->allowedChunk($chunk, $observedBytes, $options->maxOutputBytes);
            $observedBytes += strlen($allowed);
            $this->emit($index, $allowed, $stdout, $stderr, $options);
            if ($options->maxOutputBytes !== null && strlen($allowed) < strlen($chunk)) {
                $overflowed = true;
            }
        }

        return $overflowed;
    }

    private function emit(
        int $index,
        string $chunk,
        string &$stdout,
        string &$stderr,
        ProcessOptions $options,
    ): void {
        if ($chunk === '') {
            return;
        }

        if ($index === 1) {
            $this->emitStdout($chunk, $stdout, $options);

            return;
        }

        if ($options->captureOutput) {
            $stderr .= $chunk;
        }
        if ($options->passthrough) {
            fwrite(STDERR, $chunk);
        }
        if ($options->onStderr !== null) {
            ($options->onStderr)($chunk);
        }
    }

    private function emitStdout(string $chunk, string &$stdout, ProcessOptions $options): void
    {
        if ($options->captureOutput) {
            $stdout .= $chunk;
        }
        if ($options->passthrough) {
            fwrite(STDOUT, $chunk);
        }
        if ($options->onStdout !== null) {
            ($options->onStdout)($chunk);
        }
    }

    /**
     * @param array<int, resource> $pipes
     */
    private function poll(
        array $pipes,
        ProcessOptions $options,
        string &$stdout,
        string &$stderr,
        int &$observedBytes,
        int &$lastActivityAt,
        ?int $interruptedSignal,
        string $input,
        int &$inputOffset,
    ): ?ProcessTerminationReason {
        $read = $this->readablePipes($pipes);
        $stdin = $pipes[0] ?? null;
        $write = is_resource($stdin) && $inputOffset < strlen($input) ? [$stdin] : [];
        if ($read === [] && $write === []) {
            usleep(10_000);

            return null;
        }

        $ready = $this->select($read, $write);
        if ($ready === false) {
            return $interruptedSignal !== null
                ? ProcessTerminationReason::Interrupted
                : ProcessTerminationReason::IoError;
        }
        if ($ready === 0) {
            return null;
        }

        $reason = $this->consumeReady(
            $read,
            $pipes,
            $options,
            $stdout,
            $stderr,
            $observedBytes,
            $lastActivityAt,
        );
        if ($reason !== null || $write === [] || !is_resource($stdin)) {
            return $reason;
        }

        return $this->writeReadyInput($stdin, $input, $inputOffset, $lastActivityAt, $pipes);
    }

    /** @param array<int, resource> $pipes */
    private function preparePipes(array &$pipes, ProcessOptions $options): void
    {
        if (isset($pipes[0])) {
            if ($options->input === null || $options->input === '') {
                fclose($pipes[0]);
                unset($pipes[0]);
            } else {
                stream_set_blocking($pipes[0], false);
            }
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }
    }

    /**
     * @param array<int, resource> $pipes
     * @return list<resource>
     */
    private function readablePipes(array $pipes): array
    {
        $read = [];
        foreach ([1, 2] as $index) {
            $stream = $pipes[$index] ?? null;
            if (is_resource($stream) && !feof($stream)) {
                $read[] = $stream;
            }
        }

        return $read;
    }

    private function requestedTermination(
        ProcessOptions $options,
        int $startedAt,
        int $lastActivityAt,
        ?int $interruptedSignal,
    ): ?ProcessTerminationReason {
        if ($interruptedSignal !== null) {
            return ProcessTerminationReason::Interrupted;
        }
        if ($options->cancelled !== null && ($options->cancelled)()) {
            return ProcessTerminationReason::Cancelled;
        }
        if ($options->heartbeat !== null && !($options->heartbeat)()) {
            return ProcessTerminationReason::HeartbeatLost;
        }

        $now = ProcessOutcome::clock();
        if ($this->deadlineReached($startedAt, $options->timeoutSeconds, $now)) {
            return ProcessTerminationReason::TimedOut;
        }
        if ($this->deadlineReached($lastActivityAt, $options->idleTimeoutSeconds, $now)) {
            return ProcessTerminationReason::IdleTimedOut;
        }

        return null;
    }

    /**
     * stream_select() filters the ready lists by reference.
     *
     * @param list<resource> $read
     * @param list<resource> $write
     * @param-out array<array-key, resource> $read
     * @param-out array<array-key, resource> $write
     */
    private function select(array &$read, array &$write): int|false
    {
        $except = [];

        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            return stream_select($read, $write, $except, 0, 20_000);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param resource $stdin
     * @param array<int, resource> $pipes
     */
    private function writeReadyInput($stdin, string $input, int &$offset, int &$lastActivityAt, array &$pipes): ?ProcessTerminationReason
    {
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            $written = fwrite($stdin, substr($input, $offset, 8192));
        } finally {
            restore_error_handler();
        }

        if ($written === false) {
            return ProcessTerminationReason::IoError;
        }

        $offset += $written;
        if ($written > 0) {
            $lastActivityAt = ProcessOutcome::clock();
        }
        if ($offset >= strlen($input)) {
            fclose($stdin);
            unset($pipes[0]);
        }

        return null;
    }
}
