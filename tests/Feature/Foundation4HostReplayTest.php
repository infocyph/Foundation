<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport;
use Symfony\Component\Process\Process;

it('replays the exact captured Composer locks without resolving dependencies', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-lock-replay-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $contents = json_encode(['packages' => [], 'packages-dev' => []], JSON_THROW_ON_ERROR);
    file_put_contents($directory . '/baseline.lock', $contents);
    file_put_contents($directory . '/candidate.lock', $contents);

    try {
        $process = new Process([
            PHP_BINARY, dirname(__DIR__, 2) . '/benchmarks/foundation4-host-replay-locks.php',
            $directory, hash('sha256', $contents), $directory . '/verified',
        ]);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
            ->and(file_get_contents($directory . '/verified/baseline.lock'))->toBe($contents)
            ->and(file_get_contents($directory . '/verified/candidate.lock'))->toBe($contents);
    } finally {
        BenchmarkSupport::removeDirectory($directory);
    }
});

it('rejects unreviewed or malformed lock artifacts before publishing either file', function (string $failure): void {
    $directory = sys_get_temp_dir() . '/foundation-lock-rejection-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $contents = $failure === 'malformed' ? '"not a Composer lock"' : '{"packages":[],"packages-dev":[]}';
    file_put_contents($directory . '/baseline.lock', $failure === 'baseline' ? 'changed' : $contents);
    if ($failure !== 'missing') {
        file_put_contents($directory . '/candidate.lock', $failure === 'candidate' ? 'changed' : $contents);
    }

    try {
        $process = new Process([
            PHP_BINARY, dirname(__DIR__, 2) . '/benchmarks/foundation4-host-replay-locks.php',
            $directory, $failure === 'digest' ? 'invalid' : hash('sha256', $contents), $directory . '/verified',
        ]);
        $process->run();
        $message = match ($failure) {
            'malformed' => 'complete Composer lock files',
            'digest' => 'Usage: replay-locks',
            default => 'Reviewed shared-lock digest mismatch',
        };
        expect($process->getExitCode())->not->toBe(0, $process->getErrorOutput() . $process->getOutput())
            ->and($process->getErrorOutput())->toContain($message)
            ->and(is_dir($directory . '/verified'))->toBeFalse();
    } finally {
        BenchmarkSupport::removeDirectory($directory);
    }
})->with(['baseline', 'candidate', 'missing', 'malformed', 'digest']);

it('captures Linux pressure and throttling counters without recording credentials', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-host-diagnostics-' . bin2hex(random_bytes(6));
    try {
        $process = new Process([
            'bash', dirname(__DIR__, 2) . '/benchmarks/foundation4-host-diagnostics.sh', $directory,
        ], null, [
            'FOUNDATION_HOST_WORKLOAD_PROFILE' => 'json-minimal',
            'FOUNDATION_HOST_POSTGRES_CONTAINER' => false,
            'FOUNDATION_HOST_REDIS_CONTAINER' => false,
            'FOUNDATION_HOST_PG_PASSWORD' => 'diagnostic-secret-must-stay-private',
        ]);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
            ->and(file_get_contents($directory . '/cpu-stat.txt'))->toStartWith('cpu ')
            ->and(file_get_contents($directory . '/pressure-cpu.txt'))->not->toBe('')
            ->and(file_get_contents($directory . '/cgroup-cpu.stat.txt'))->not->toBe('');
    } finally {
        BenchmarkSupport::removeDirectory($directory);
    }
});
