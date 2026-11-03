<?php

declare(strict_types=1);

use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;
use Infocyph\Foundation\Process\ProcessTerminationReason;

it('enforces wall-clock timeout while a child refuses to read stdin', function (): void {
    $started = hrtime(true);
    $result = new ProcessRunner()->run(
        [PHP_BINARY, '-r', 'usleep(600000); echo "late";'],
        new ProcessOptions(
            timeoutSeconds: 0.05,
            input: str_repeat('A', 1_048_576),
            terminationGraceSeconds: 0.05,
            handleSignals: false,
        ),
    );
    $wallSeconds = (hrtime(true) - $started) / 1_000_000_000;

    expect($result->timedOut)->toBeTrue()
        ->and($result->reason)->toBe(ProcessTerminationReason::TimedOut)
        ->and($wallSeconds)->toBeLessThan(0.5)
        ->and($result->durationNanoseconds)->toBeGreaterThan(40_000_000);
});

it('simultaneously drains child output and supplies stdin without deadlock', function (): void {
    $result = new ProcessRunner()->run(
        [PHP_BINARY, '-r', 'echo str_repeat("O", 131072); echo strlen(stream_get_contents(STDIN));'],
        new ProcessOptions(
            timeoutSeconds: 2.0,
            input: str_repeat('I', 131072),
            handleSignals: false,
        ),
    );

    expect($result->timedOut)->toBeFalse()
        ->and($result->exitCode)->toBe(0)
        ->and($result->stdout)->toEndWith('131072');
});
