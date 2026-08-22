<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process;

final class ProcessRunner
{
    private const int SIGNAL_INTERRUPT = 2;

    private const int SIGNAL_KILL = 9;

    private const int SIGNAL_TERMINATE = 15;

    /** @param list<string>|string $command Prefer an argument list to bypass the shell. */
    public function run(array|string $command, ?ProcessOptions $options = null): ProcessResult
    {
        $options ??= new ProcessOptions();
        $this->assertCommand($command);

        if ($options->interactive) {
            return $this->runInteractive($command, $options);
        }

        $descriptors = [
            0 => $options->inheritInput ? STDIN : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $options->cwd,
            $this->environment($options),
            ['bypass_shell' => is_array($command)],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start process.');
        }

        try {
            return $this->capture($process, $pipes, $options);
        } catch (\Throwable $exception) {
            $this->closePipes($pipes);
            $this->terminate($process, $options->terminationGraceSeconds);
            proc_close($process);

            throw $exception;
        }
    }

    /** @param list<string>|string $command */
    private function assertCommand(array|string $command): void
    {
        if (!is_array($command)) {
            if (trim($command) === '') {
                throw new \InvalidArgumentException('Process command cannot be empty.');
            }

            return;
        }
        if ($command === [] || array_any(
            $command,
            static fn(mixed $part): bool => !is_string($part) || $part === '',
        )) {
            throw new \InvalidArgumentException('Process command arguments must be non-empty strings.');
        }
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function capture($process, array $pipes, ProcessOptions $options): ProcessResult
    {
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            if ($options->input !== null && $options->input !== '') {
                $this->writeInput($pipes[0], $options->input);
            }
            fclose($pipes[0]);
            unset($pipes[0]);
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $stdout = '';
        $stderr = '';
        $capturedBytes = 0;
        $startedAt = hrtime(true);
        $lastActivityAt = $startedAt;
        $reason = null;
        $interruptedSignal = null;
        $signalState = $this->registerSignals(
            $options,
            static function (int $signal) use (&$interruptedSignal): void {
                $interruptedSignal = $signal;
            },
        );
        $lastStatus = proc_get_status($process);

        try {
            while (($lastStatus = proc_get_status($process))['running']) {
                if ($interruptedSignal !== null) {
                    $reason = ProcessTerminationReason::Interrupted;

                    break;
                }
                if ($options->cancelled !== null && ($options->cancelled)()) {
                    $reason = ProcessTerminationReason::Cancelled;

                    break;
                }
                if ($options->heartbeat !== null && !($options->heartbeat)()) {
                    $reason = ProcessTerminationReason::HeartbeatLost;

                    break;
                }

                $now = hrtime(true);
                if ($this->deadlineReached($startedAt, $options->timeoutSeconds, $now)) {
                    $reason = ProcessTerminationReason::TimedOut;

                    break;
                }
                if ($this->deadlineReached($lastActivityAt, $options->idleTimeoutSeconds, $now)) {
                    $reason = ProcessTerminationReason::IdleTimedOut;

                    break;
                }

                $read = $this->readablePipes($pipes);
                if ($read === []) {
                    usleep(10_000);

                    continue;
                }

                $write = $except = [];
                $ready = @stream_select($read, $write, $except, 0, 20_000);
                if ($ready === false) {
                    if ($interruptedSignal !== null) {
                        $reason = ProcessTerminationReason::Interrupted;

                        break;
                    }
                    $reason = ProcessTerminationReason::IoError;

                    break;
                }
                if ($ready === 0) {
                    continue;
                }

                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        $reason = ProcessTerminationReason::IoError;

                        break 2;
                    }
                    if ($chunk === '') {
                        continue;
                    }

                    $lastActivityAt = hrtime(true);
                    $index = $stream === ($pipes[1] ?? null) ? 1 : 2;
                    $allowed = $this->allowedChunk($chunk, $capturedBytes, $options->maxOutputBytes);
                    if ($index === 1) {
                        $stdout .= $allowed;
                        if ($options->passthrough && $allowed !== '') {
                            fwrite(STDOUT, $allowed);
                        }
                        if ($options->onStdout !== null && $allowed !== '') {
                            ($options->onStdout)($allowed);
                        }
                    } else {
                        $stderr .= $allowed;
                        if ($options->passthrough && $allowed !== '') {
                            fwrite(STDERR, $allowed);
                        }
                        if ($options->onStderr !== null && $allowed !== '') {
                            ($options->onStderr)($allowed);
                        }
                    }
                    $capturedBytes += strlen($allowed);
                    if ($options->maxOutputBytes !== null && strlen($allowed) < strlen($chunk)) {
                        $reason = ProcessTerminationReason::OutputLimit;

                        break 2;
                    }
                }
            }

            if ($reason !== null) {
                $this->terminate($process, $options->terminationGraceSeconds);
            }

            $this->drain($pipes, $stdout, $stderr, $capturedBytes, $options);
            $lastStatus = proc_get_status($process);
        } finally {
            $this->restoreSignals($signalState);
            $this->closePipes($pipes);
        }

        $closedExit = proc_close($process);
        $reason ??= ProcessTerminationReason::Exited;
        $signal = is_array($lastStatus)
            && ($lastStatus['signaled'] ?? false)
            && is_int($lastStatus['termsig'] ?? null)
            ? $lastStatus['termsig']
            : $interruptedSignal;
        $exitCode = $this->exitCode($reason, $lastStatus, $closedExit, $signal);

        return new ProcessResult(
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            timedOut: in_array(
                $reason,
                [ProcessTerminationReason::TimedOut, ProcessTerminationReason::IdleTimedOut],
                true,
            ),
            reason: $reason,
            signal: $signal,
            durationNanoseconds: max(0, hrtime(true) - $startedAt),
        );
    }

    private function allowedChunk(string $chunk, int $capturedBytes, ?int $limit): string
    {
        if ($limit === null) {
            return $chunk;
        }

        $remaining = $limit - $capturedBytes;
        if ($remaining <= 0) {
            return '';
        }

        return strlen($chunk) <= $remaining ? $chunk : substr($chunk, 0, $remaining);
    }

    /** @param array<int, resource> $pipes */
    private function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
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
        int &$capturedBytes,
        ProcessOptions $options,
    ): void {
        foreach ([1, 2] as $index) {
            $stream = $pipes[$index] ?? null;
            if (!is_resource($stream)) {
                continue;
            }
            $chunk = stream_get_contents($stream);
            if (!is_string($chunk) || $chunk === '') {
                continue;
            }
            $allowed = $this->allowedChunk($chunk, $capturedBytes, $options->maxOutputBytes);
            $capturedBytes += strlen($allowed);
            if ($index === 1) {
                $stdout .= $allowed;
                if ($options->passthrough && $allowed !== '') {
                    fwrite(STDOUT, $allowed);
                }
                if ($options->onStdout !== null && $allowed !== '') {
                    ($options->onStdout)($allowed);
                }
            } else {
                $stderr .= $allowed;
                if ($options->passthrough && $allowed !== '') {
                    fwrite(STDERR, $allowed);
                }
                if ($options->onStderr !== null && $allowed !== '') {
                    ($options->onStderr)($allowed);
                }
            }
        }
    }

    /** @return array<string, string>|null */
    private function environment(ProcessOptions $options): ?array
    {
        if ($options->environment === []) {
            return null;
        }

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        foreach ($options->environment as $key => $value) {
            $environment[$key] = $value;
        }

        /** @var array<string, string> $environment */
        return $environment;
    }

    /** @param array<string, mixed> $status */
    private function exitCode(
        ProcessTerminationReason $reason,
        array $status,
        int $closedExit,
        ?int $signal,
    ): int {
        if ($reason === ProcessTerminationReason::Exited) {
            $statusExit = $status['exitcode'] ?? -1;
            if (is_int($statusExit) && $statusExit >= 0) {
                return $statusExit;
            }

            return $closedExit >= 0 ? $closedExit : 1;
        }

        return match ($reason) {
            ProcessTerminationReason::TimedOut,
            ProcessTerminationReason::IdleTimedOut => 124,
            ProcessTerminationReason::Interrupted => 128 + ($signal ?? self::SIGNAL_INTERRUPT),
            default => 1,
        };
    }

    /** @param array<int, resource> $pipes @return list<resource> */
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

    /**
     * @param callable(int):void $interrupt
     * @return array{async:?bool,handlers:array<int,callable|int>}|null
     */
    private function registerSignals(ProcessOptions $options, callable $interrupt): ?array
    {
        if (!$options->handleSignals
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
        ) {
            return null;
        }

        $handlers = [];
        foreach ([self::SIGNAL_INTERRUPT, self::SIGNAL_TERMINATE] as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
        }
        $async = pcntl_async_signals();
        pcntl_async_signals(true);
        foreach (array_keys($handlers) as $signal) {
            pcntl_signal($signal, static fn(int $received): mixed => $interrupt($received), false);
        }

        return ['async' => $async, 'handlers' => $handlers];
    }

    /** @param array{async:?bool,handlers:array<int,callable|int>}|null $state */
    private function restoreSignals(?array $state): void
    {
        if ($state === null || !function_exists('pcntl_signal')) {
            return;
        }
        foreach ($state['handlers'] as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        if ($state['async'] !== null && function_exists('pcntl_async_signals')) {
            pcntl_async_signals($state['async']);
        }
    }

    /** @param list<string>|string $command */
    private function runInteractive(array|string $command, ProcessOptions $options): ProcessResult
    {
        $process = proc_open(
            $command,
            [STDIN, STDOUT, STDERR],
            $pipes,
            $options->cwd,
            $this->environment($options),
            ['bypass_shell' => is_array($command)],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start interactive process.');
        }

        $startedAt = hrtime(true);
        $reason = null;
        $interruptedSignal = null;
        $signalState = $this->registerSignals(
            $options,
            static function (int $signal) use (&$interruptedSignal): void {
                $interruptedSignal = $signal;
            },
        );
        $status = proc_get_status($process);

        try {
            while (($status = proc_get_status($process))['running']) {
                if ($interruptedSignal !== null) {
                    $reason = ProcessTerminationReason::Interrupted;

                    break;
                }
                if ($options->cancelled !== null && ($options->cancelled)()) {
                    $reason = ProcessTerminationReason::Cancelled;

                    break;
                }
                if ($options->heartbeat !== null && !($options->heartbeat)()) {
                    $reason = ProcessTerminationReason::HeartbeatLost;

                    break;
                }
                if ($this->deadlineReached($startedAt, $options->timeoutSeconds, hrtime(true))) {
                    $reason = ProcessTerminationReason::TimedOut;

                    break;
                }
                usleep(10_000);
            }

            if ($reason !== null) {
                $this->terminate($process, $options->terminationGraceSeconds);
            }
            $status = proc_get_status($process);
        } finally {
            $this->restoreSignals($signalState);
        }

        $closedExit = proc_close($process);
        $reason ??= ProcessTerminationReason::Exited;
        $signal = ($status['signaled'] ?? false) && is_int($status['termsig'] ?? null)
            ? $status['termsig']
            : $interruptedSignal;

        return new ProcessResult(
            exitCode: $this->exitCode($reason, $status, $closedExit, $signal),
            timedOut: $reason === ProcessTerminationReason::TimedOut,
            reason: $reason,
            signal: $signal,
            durationNanoseconds: max(0, hrtime(true) - $startedAt),
        );
    }

    /** @param resource $process */
    private function terminate($process, float $graceSeconds): void
    {
        $status = proc_get_status($process);
        if (!$status['running']) {
            return;
        }

        @proc_terminate($process, self::SIGNAL_TERMINATE);
        $deadline = hrtime(true) + (int) round($graceSeconds * 1_000_000_000);
        while (proc_get_status($process)['running'] && hrtime(true) < $deadline) {
            usleep(10_000);
        }
        if (proc_get_status($process)['running']) {
            @proc_terminate($process, self::SIGNAL_KILL);
        }
    }

    /** @param resource $stdin */
    private function writeInput($stdin, string $input): void
    {
        $remaining = $input;
        while ($remaining !== '') {
            $written = fwrite($stdin, $remaining);
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write process input.');
            }
            $remaining = substr($remaining, $written);
        }
    }
}
