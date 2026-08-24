<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process\Internal;

final class ProcessTree
{
    private const int SIGNAL_KILL = 9;

    private const int SIGNAL_TERMINATE = 15;

    /** @param array{pid:?int,group:bool} $tree */
    public function alive(array $tree): bool
    {
        $pid = $tree['pid'];
        if (!$tree['group'] || $pid === null || !function_exists('posix_kill')) {
            return false;
        }

        return $this->sendPosixSignal(-$pid, 0);
    }

    /**
     * @param resource $process
     * @return array{pid:?int,group:bool}
     */
    public function prepare($process, bool $interactive): array
    {
        $status = proc_get_status($process);
        $pid = $status['pid'] > 0 ? $status['pid'] : null;
        if ($pid === null || $interactive || PHP_OS_FAMILY === 'Windows') {
            return ['pid' => $pid, 'group' => false];
        }
        if (!function_exists('posix_setpgid') || !function_exists('posix_getpgid') || !function_exists('posix_kill')) {
            return ['pid' => $pid, 'group' => false];
        }

        return ['pid' => $pid, 'group' => $this->prepareGroup($pid)];
    }

    /**
     * @param resource $process
     * @param array{pid:?int,group:bool} $tree
     */
    public function terminate($process, float $graceSeconds, array $tree): void
    {
        $status = proc_get_status($process);
        if (!$status['running'] && !$this->alive($tree)) {
            return;
        }

        $this->signal($process, $tree, self::SIGNAL_TERMINATE, force: false);
        $deadline = ProcessOutcome::clock() + (int) round($graceSeconds * 1_000_000_000);
        while ($this->running($process, $tree) && ProcessOutcome::clock() < $deadline) {
            usleep(10_000);
        }
        if ($this->running($process, $tree)) {
            $this->signal($process, $tree, self::SIGNAL_KILL, force: true);
        }
    }

    /**
     * @param list<string> $command
     * @param array<int, resource> $pipes
     * @return resource|false
     */
    private function openWindowsTerminator(array $command, array &$pipes)
    {
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            return proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true, 'create_process_group' => true],
            );
        } finally {
            restore_error_handler();
        }
    }

    private function prepareGroup(int $pid): bool
    {
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            return posix_setpgid($pid, $pid) || posix_getpgid($pid) === $pid;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param resource $process
     * @param array{pid:?int,group:bool} $tree
     */
    private function running($process, array $tree): bool
    {
        return $this->alive($tree) || proc_get_status($process)['running'];
    }

    private function sendPosixSignal(int $pid, int $signal): bool
    {
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            return posix_kill($pid, $signal);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param resource $process
     * @param array{pid:?int,group:bool} $tree
     */
    private function signal($process, array $tree, int $signal, bool $force): void
    {
        $pid = $tree['pid'];
        if (PHP_OS_FAMILY === 'Windows' && $pid !== null && $this->windowsTerminate($pid, $force)) {
            return;
        }
        if ($tree['group'] && $pid !== null && function_exists('posix_kill')) {
            $this->sendPosixSignal(-$pid, $signal);

            return;
        }

        proc_terminate($process, $signal);
    }

    private function windowsTerminate(int $pid, bool $force): bool
    {
        $command = ['taskkill', '/PID', (string) $pid, '/T'];
        if ($force) {
            $command[] = '/F';
        }

        $pipes = [];
        $process = $this->openWindowsTerminator($command, $pipes);
        if (!is_resource($process)) {
            return false;
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return proc_close($process) === 0;
    }
}
