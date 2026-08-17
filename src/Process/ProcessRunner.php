<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process;

final class ProcessRunner
{
    /**
     * @param list<string>|string $command Prefer an argument list to bypass the shell.
     */
    public function run(array|string $command, ?ProcessOptions $options = null): ProcessResult
    {
        $options ??= new ProcessOptions();
        if (is_array($command) && ($command === [] || array_any($command, static fn(mixed $part): bool => !is_string($part) || $part === ''))) {
            throw new \InvalidArgumentException('Process command arguments must be non-empty strings.');
        }

        if ($options->interactive) {
            return $this->runInteractive($command, $options);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $options->cwd,
            $options->environment === [] ? null : $options->environment + $_ENV,
            ['bypass_shell' => is_array($command)],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start process.');
        }

        try {
            fwrite($pipes[0], $options->input ?? '');
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $startedAt = hrtime(true);
            $timedOut = false;

            while (true) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }

                if ($options->timeoutSeconds !== null
                    && (hrtime(true) - $startedAt) / 1_000_000_000 >= $options->timeoutSeconds
                ) {
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

            return new ProcessResult(
                exitCode: $timedOut ? 124 : $exitCode,
                stdout: $stdout,
                stderr: $stderr,
                timedOut: $timedOut,
            );
        } catch (\Throwable $exception) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);

            throw $exception;
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
            $options->environment === [] ? null : $options->environment + $_ENV,
            ['bypass_shell' => is_array($command)],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start interactive process.');
        }

        $startedAt = hrtime(true);
        $timedOut = false;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ($options->timeoutSeconds !== null
                && (hrtime(true) - $startedAt) / 1_000_000_000 >= $options->timeoutSeconds
            ) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(10_000);
        }

        $exitCode = proc_close($process);

        return new ProcessResult($timedOut ? 124 : $exitCode, timedOut: $timedOut);
    }
}
