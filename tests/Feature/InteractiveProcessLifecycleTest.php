<?php

declare(strict_types=1);

use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;

it('terminates an interactive subprocess when its lifecycle callback throws', function (string $hook): void {
    $pidFile = tempnam(sys_get_temp_dir(), 'foundation-interactive-pid-');
    expect($pidFile)->toBeString();

    try {
        $probe = static function () use ($hook, $pidFile): bool {
            clearstatcache(true, $pidFile);
            $pid = trim((string) file_get_contents($pidFile));
            if ($pid === '') {
                return $hook === 'heartbeat';
            }

            throw new LogicException('Interactive ' . $hook . ' callback failed.');
        };

        $options = new ProcessOptions(
            interactive: true,
            cancelled: $hook === 'cancelled' ? $probe : null,
            heartbeat: $hook === 'heartbeat' ? $probe : null,
            terminationGraceSeconds: 0.1,
            handleSignals: false,
        );
        $command = [
            PHP_BINARY,
            '-r',
            'file_put_contents(' . var_export($pidFile, true) . ', (string) getmypid()); usleep(4000000);',
        ];

        expect(fn() => new ProcessRunner()->run($command, $options))
            ->toThrow(LogicException::class, 'Interactive ' . $hook . ' callback failed.');

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
