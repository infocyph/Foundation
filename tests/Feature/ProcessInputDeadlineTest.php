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

it('observes cancellation while a child blocks stdin consumption', function (): void {
    $checks = 0;
    $started = hrtime(true);
    $result = new ProcessRunner()->run(
        [PHP_BINARY, '-r', 'usleep(750000);'],
        new ProcessOptions(
            timeoutSeconds: 2.0,
            input: str_repeat('I', 1_048_576),
            cancelled: static function () use (&$checks): bool {
                return ++$checks >= 4;
            },
            terminationGraceSeconds: 0.05,
            handleSignals: false,
        ),
    );
    $wallSeconds = (hrtime(true) - $started) / 1_000_000_000;

    expect($result->reason)->toBe(ProcessTerminationReason::Cancelled)
        ->and($result->timedOut)->toBeFalse()
        ->and($wallSeconds)->toBeLessThan(0.5);
});

it('cleans up when a child exits before consuming all standard input', function (): void {
    $started = hrtime(true);
    $result = new ProcessRunner()->run(
        [PHP_BINARY, '-r', 'echo "done";'],
        new ProcessOptions(
            timeoutSeconds: 1.0,
            input: str_repeat('I', 1_048_576),
            terminationGraceSeconds: 0.05,
            handleSignals: false,
        ),
    );

    expect($result->timedOut)->toBeFalse()
        ->and($result->stdout)->toContain('done')
        ->and((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(1.0);
});
