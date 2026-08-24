<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process;

use Infocyph\Foundation\Process\Internal\ProcessCapture;
use Infocyph\Foundation\Process\Internal\ProcessOutcome;
use Infocyph\Foundation\Process\Internal\ProcessSignals;
use Infocyph\Foundation\Process\Internal\ProcessTree;

final class ProcessRunner
{
    private readonly ProcessCapture $capture;

    private readonly ProcessSignals $signals;

    private readonly ProcessTree $tree;

    public function __construct()
    {
        $this->signals = new ProcessSignals();
        $this->tree = new ProcessTree();
        $this->capture = new ProcessCapture($this->tree, $this->signals);
    }

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
            $this->procOptions($command),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start process.');
        }

        /** @var array<int, resource> $pipes */
        $tree = $this->tree->prepare($process, interactive: false);

        try {
            return $this->capture->run($process, $pipes, $options, $tree);
        } catch (\Throwable $exception) {
            $this->closePipes($pipes);
            $this->tree->terminate($process, $options->terminationGraceSeconds, $tree);
            proc_close($process);

            throw $exception;
        }
    }

    /** @param list<string>|string $command */
    private function assertCommand(array|string $command): void
    {
        if (is_string($command)) {
            if (trim($command) === '') {
                throw new \InvalidArgumentException('Process command cannot be empty.');
            }

            return;
        }
        if ($command === [] || array_any($command, static fn(string $part): bool => $part === '')) {
            throw new \InvalidArgumentException('Process command arguments must be non-empty strings.');
        }
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

    /** @return array<string, string>|null */
    private function environment(ProcessOptions $options): ?array
    {
        if ($options->environment === []) {
            return null;
        }

        $environment = getenv();
        foreach ($options->environment as $key => $value) {
            $environment[$key] = $value;
        }

        return $environment;
    }

    /**
     * @param list<string>|string $command
     * @return array<string, bool>
     */
    private function procOptions(array|string $command): array
    {
        $options = ['bypass_shell' => is_array($command)];
        if (PHP_OS_FAMILY === 'Windows') {
            $options['create_process_group'] = true;
        }

        return $options;
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
            $this->procOptions($command),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start interactive process.');
        }

        $tree = $this->tree->prepare($process, interactive: true);
        $startedAt = ProcessOutcome::clock();
        $reason = null;
        $interruptedSignal = null;
        $signalState = $this->signals->register(
            $options,
            static function (int $signal) use (&$interruptedSignal): void {
                $interruptedSignal = $signal;
            },
        );
        $status = proc_get_status($process);

        try {
            while (($status = proc_get_status($process))['running']) {
                $reason = $this->interactiveTermination($options, $startedAt, $interruptedSignal);
                if ($reason !== null) {
                    break;
                }
                usleep(10_000);
            }

            if ($reason !== null) {
                $this->tree->terminate($process, $options->terminationGraceSeconds, $tree);
            }
            $status = proc_get_status($process);
        } finally {
            $this->signals->restore($signalState);
        }

        $closedExit = proc_close($process);
        $reason ??= ProcessTerminationReason::Exited;
        $signal = ProcessOutcome::signal($status, $interruptedSignal);

        return new ProcessResult(
            exitCode: ProcessOutcome::exitCode($reason, $status, $closedExit, $signal),
            timedOut: $reason === ProcessTerminationReason::TimedOut,
            reason: $reason,
            signal: $signal,
            durationNanoseconds: max(0, ProcessOutcome::clock() - $startedAt),
        );
    }

    private function interactiveTermination(
        ProcessOptions $options,
        int $startedAt,
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
        if ($options->timeoutSeconds !== null
            && (ProcessOutcome::clock() - $startedAt) / 1_000_000_000 >= $options->timeoutSeconds
        ) {
            return ProcessTerminationReason::TimedOut;
        }

        return null;
    }
}
