<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process;

final class ProcessRunner
{
    /** @param list<string>|string $command Prefer an argument list to bypass the shell. */
    public function run(array|string $command, ?ProcessOptions $options = null): ProcessResult
    {
        $options ??= new ProcessOptions;
        $this->assertCommand($command);

        if ($options->interactive) {
            return $this->runInteractive($command, $options);
        }

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $options->cwd,
            $this->environment($options),
            ['bypass_shell' => is_array($command)],
        );
        if (! is_resource($process)) {
            throw new \RuntimeException('Unable to start process.');
        }

        try {
            return $this->capture($process, $pipes, $options);
        } catch (\Throwable $exception) {
            $this->closePipes($pipes);
            proc_terminate($process);
            proc_close($process);

            throw $exception;
        }
    }

    /** @param list<string>|string $command */
    private function assertCommand(array|string $command): void
    {
        if (! is_array($command)) {
            return;
        }
        if ($command === [] || array_any($command, static fn (string $part): bool => $part === '')) {
            throw new \InvalidArgumentException('Process command arguments must be non-empty strings.');
        }
    }

    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private function capture($process, array $pipes, ProcessOptions $options): ProcessResult
    {
        fwrite($pipes[0], $options->input ?? '');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = hrtime(true);
        $timedOut = false;

        while ($this->running($process)) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            if ($this->timeoutReached($startedAt, $options->timeoutSeconds)) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(10_000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return new ProcessResult($timedOut ? 124 : $exitCode, $stdout, $stderr, $timedOut);
    }

    /** @param array<int, mixed> $pipes */
    private function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function environment(ProcessOptions $options): ?array
    {
        if ($options->environment === []) {
            return null;
        }

        $environment = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($key)) {
                $environment[$key] = $value;
            }
        }
        foreach ($options->environment as $key => $value) {
            $environment[$key] = $value;
        }

        return $environment;
    }

    /** @param resource $process */
    private function running($process): bool
    {
        return proc_get_status($process)['running'];
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
        if (! is_resource($process)) {
            throw new \RuntimeException('Unable to start interactive process.');
        }

        $startedAt = hrtime(true);
        $timedOut = false;
        while ($this->running($process)) {
            if ($this->timeoutReached($startedAt, $options->timeoutSeconds)) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(10_000);
        }

        $exitCode = proc_close($process);

        return new ProcessResult($timedOut ? 124 : $exitCode, timedOut: $timedOut);
    }

    private function timeoutReached(int $startedAt, ?float $timeoutSeconds): bool
    {
        return $timeoutSeconds !== null
            && (hrtime(true) - $startedAt) / 1_000_000_000 >= $timeoutSeconds;
    }
}
