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

it('preserves input bytes while a child consumes a large stream in small delayed reads', function (): void {
    $input = str_repeat('stream-data-0123456789', 32_768);
    $script = <<<'PHP'
$hash = hash_init('sha256');
while (!feof(STDIN)) {
    $chunk = fread(STDIN, 1024);
    if ($chunk === false || $chunk === '') {
        break;
    }
    hash_update($hash, $chunk);
    usleep(250);
}
echo hash_final($hash);
PHP;

    $result = new ProcessRunner()->run(
        [PHP_BINARY, '-r', $script],
        new ProcessOptions(timeoutSeconds: 3.0, input: $input, handleSignals: false),
    );

    expect($result->reason)->toBe(ProcessTerminationReason::Exited)
        ->and($result->exitCode)->toBe(0)
        ->and($result->timedOut)->toBeFalse()
        ->and($result->stdout)->toBe(hash('sha256', $input));
});

it('terminates a chatty child at the output limit while stdin is pending', function (): void {
    $started = hrtime(true);
    $result = new ProcessRunner()->run(
        [PHP_BINARY, '-r', 'echo str_repeat("O", 131072); usleep(500000); stream_get_contents(STDIN);'],
        new ProcessOptions(
            input: str_repeat('I', 1_048_576),
            maxOutputBytes: 4096,
            timeoutSeconds: 2.0,
            terminationGraceSeconds: 0.05,
            handleSignals: false,
        ),
    );

    expect($result->reason)->toBe(ProcessTerminationReason::OutputLimit)
        ->and(strlen($result->stdout))->toBeLessThanOrEqual(4096)
        ->and((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(1.5);
});

it('preserves lifecycle callback failures and reaps a noninteractive child with pending input', function (string $hook): void {
    $pidFile = tempnam(sys_get_temp_dir(), 'foundation-input-pid-');
    expect($pidFile)->toBeString();

    try {
        $probe = static function () use ($hook, $pidFile): bool {
            clearstatcache(true, $pidFile);
            $pid = trim((string) file_get_contents($pidFile));
            if ($pid === '') {
                return $hook === 'heartbeat';
            }

            throw new LogicException('Pending input ' . $hook . ' failed.');
        };

        $command = [
            PHP_BINARY,
            '-r',
            'file_put_contents(' . var_export($pidFile, true) . ', (string) getmypid()); usleep(4000000);',
        ];
        $options = new ProcessOptions(
            input: str_repeat('I', 1_048_576),
            cancelled: $hook === 'cancelled' ? $probe : null,
            heartbeat: $hook === 'heartbeat' ? $probe : null,
            terminationGraceSeconds: 0.1,
            handleSignals: false,
        );

        expect(fn() => new ProcessRunner()->run($command, $options))
            ->toThrow(LogicException::class, 'Pending input ' . $hook . ' failed.');

        $pid = (int) file_get_contents($pidFile);
        expect($pid)->toBeGreaterThan(0);
        if (function_exists('posix_kill')) {
            expect(posix_kill($pid, 0))->toBeFalse();
        }
    } finally {
        if (is_file($pidFile)) {
            unlink($pidFile);
        }
    }
})->with(['heartbeat', 'cancelled']);

it('reaps a noninteractive child when an output callback fails with pending stdin', function (): void {
    $pidFile = tempnam(sys_get_temp_dir(), 'foundation-output-pid-');
    expect($pidFile)->toBeString();

    try {
        $command = [
            PHP_BINARY,
            '-r',
            'file_put_contents(' . var_export($pidFile, true) . ', (string) getmypid()); echo "ready"; flush(); usleep(4000000);',
        ];

        $options = new ProcessOptions(
            input: str_repeat('I', 1_048_576),
            onStdout: static function (string $output): void {
                if ($output !== '') {
                    throw new LogicException('Output observer failed.');
                }
            },
            terminationGraceSeconds: 0.1,
            handleSignals: false,
        );

        expect(fn() => new ProcessRunner()->run($command, $options))
            ->toThrow(LogicException::class, 'Output observer failed.');

        $pid = (int) file_get_contents($pidFile);
        expect($pid)->toBeGreaterThan(0);
        if (function_exists('posix_kill')) {
            expect(posix_kill($pid, 0))->toBeFalse();
        }
    } finally {
        if (is_file($pidFile)) {
            unlink($pidFile);
        }
    }
});

it('retries deterministic zero and partial stdin writes without skipping bytes', function (): void {
    $wrapper = new class {
        public mixed $context = null;

        public static string $captured = '';

        private int $calls = 0;

        public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
        {
            unset($path, $mode, $options, $openedPath);

            return true;
        }

        public function stream_write(string $data): int
        {
            if (++$this->calls % 2 !== 0) {
                return 0;
            }

            $partial = substr($data, 0, 3);
            self::$captured .= $partial;

            return strlen($partial);
        }

        public function stream_close(): void {}
    };
    $scheme = 'foundation-stdin-' . bin2hex(random_bytes(5));
    $class = $wrapper::class;
    expect(stream_wrapper_register($scheme, $class))->toBeTrue();

    try {
        $stream = fopen($scheme . '://stdin', 'w');
        expect($stream)->toBeResource();

        $capture = new \Infocyph\Foundation\Process\Internal\ProcessCapture(
            new \Infocyph\Foundation\Process\Internal\ProcessTree(),
            new \Infocyph\Foundation\Process\Internal\ProcessSignals(),
        );
        $method = new ReflectionMethod($capture, 'writeReadyInput');
        $input = 'abcdefgh';
        $offset = 0;
        $lastActivityAt = hrtime(true);
        $pipes = [0 => $stream];
        $offsets = [];
        for ($i = 0; $i < 12 && isset($pipes[0]); ++$i) {
            expect($method->invokeArgs($capture, [
                $stream, $input, &$offset, &$lastActivityAt, &$pipes,
            ]))->toBeNull();
            $offsets[] = $offset;
        }

        expect($offsets[0])->toBe(0)
            ->and($offsets)->toContain(3)
            ->and($offset)->toBe(strlen($input))
            ->and($class::$captured)->toBe($input)
            ->and($pipes)->not->toHaveKey(0)
            ->and(is_resource($stream))->toBeFalse();
    } finally {
        stream_wrapper_unregister($scheme);
    }
});

it('restores caller signal handlers and async mode after normal and failed process capture', function (): void {
    if (!function_exists('pcntl_async_signals')
        || !function_exists('pcntl_signal_get_handler')
        || !function_exists('pcntl_signal')
    ) {
        return;
    }

    $async = pcntl_async_signals();
    $interrupt = pcntl_signal_get_handler(SIGINT);
    $terminate = pcntl_signal_get_handler(SIGTERM);
    if ((!is_int($interrupt) && !is_callable($interrupt))
        || (!is_int($terminate) && !is_callable($terminate))
    ) {
        throw new RuntimeException('Unable to capture the original process signal handlers.');
    }

    try {
        $runner = new ProcessRunner();
        $result = $runner->run(
            [PHP_BINARY, '-r', 'echo "normal";'],
            new ProcessOptions(handleSignals: true),
        );
        expect($result->stdout)->toBe('normal');

        expect(fn() => $runner->run(
            [PHP_BINARY, '-r', 'echo "failed";'],
            new ProcessOptions(
                onStdout: static function (string $chunk): void {
                    if ($chunk !== '') {
                        throw new LogicException('Signal restoration probe.');
                    }
                },
                handleSignals: true,
            ),
        ))->toThrow(LogicException::class, 'Signal restoration probe.');

        expect(pcntl_async_signals())->toBe($async)
            ->and(pcntl_signal_get_handler(SIGINT))->toBe($interrupt)
            ->and(pcntl_signal_get_handler(SIGTERM))->toBe($terminate);
    } finally {
        pcntl_signal(SIGINT, $interrupt);
        pcntl_signal(SIGTERM, $terminate);
        pcntl_async_signals($async);
    }
});
