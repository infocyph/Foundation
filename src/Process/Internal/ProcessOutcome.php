<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process\Internal;

use Infocyph\Foundation\Process\ProcessTerminationReason;

final class ProcessOutcome
{
    private const int SIGNAL_INTERRUPT = 2;

    private function __construct() {}

    public static function clock(): int
    {
        $value = hrtime(true);

        return is_int($value) ? $value : (int) $value;
    }

    /**
     * @param array{command:string,pid:int,running:bool,signaled:bool,stopped:bool,exitcode:int,termsig:int,stopsig:int} $status
     */
    public static function exitCode(
        ProcessTerminationReason $reason,
        array $status,
        int $closedExit,
        ?int $signal,
    ): int {
        if ($reason === ProcessTerminationReason::Exited) {
            return $status['exitcode'] >= 0
                ? $status['exitcode']
                : ($closedExit >= 0 ? $closedExit : 1);
        }

        return match ($reason) {
            ProcessTerminationReason::TimedOut,
            ProcessTerminationReason::IdleTimedOut => 124,
            ProcessTerminationReason::Interrupted => 128 + ($signal ?? self::SIGNAL_INTERRUPT),
            default => 1,
        };
    }

    /**
     * @param array{command:string,pid:int,running:bool,signaled:bool,stopped:bool,exitcode:int,termsig:int,stopsig:int} $status
     */
    public static function signal(array $status, ?int $interruptedSignal): ?int
    {
        return $status['signaled'] ? $status['termsig'] : $interruptedSignal;
    }
}
