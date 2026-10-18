<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('requires tagged Foundation releases to pass quality and production consumers', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $jobs = $workflow['jobs'];

    expect($jobs['release']['needs'] ?? [])->toContain('phpforge', 'production-consumer', 'release-evidence', 'runtime-hosts')
        ->and($jobs['release']['if'])->toContain("startsWith(github.ref, 'refs/tags/')")
        ->not->toContain('always(', 'failure(', 'cancelled(');

    foreach (['phpforge', 'production-consumer'] as $job) {
        expect($jobs[$job]['if'] ?? null)->toBeNull();
    }

    expect($jobs['runtime-hosts']['uses'])->toBe('./.github/workflows/foundation4-host-certification.yml')
        ->and($jobs['runtime-hosts']['if'])->toContain("startsWith(github.ref, 'refs/tags/')");
    $hosts = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/foundation4-host-certification.yml');
    expect($hosts['on'])->toHaveKey('workflow_call');
});

it('enforces independent complete performance batches without mixing their artifacts', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/foundation4-host-certification.yml');
    $measure = $workflow['jobs']['paired-host'];
    $compare = $workflow['jobs']['compare-paired-host'];

    expect($measure['strategy']['matrix']['batch'])->toBe(['primary', 'confirmation'])
        ->and($compare['strategy']['matrix']['batch'])->toBe(['primary', 'confirmation'])
        ->and($measure['strategy']['matrix']['host'])->toBe(['fpm', 'runwire'])
        ->and($measure['strategy']['matrix']['workers'])->toBe([1, 4])
        ->and($measure['strategy']['matrix']['concurrency'])->toBe([1, 10, 50])
        ->and((int) $measure['env']['FOUNDATION_HOST_MIN_SECONDS'])->toBeGreaterThanOrEqual(60)
        ->and((int) $measure['env']['FOUNDATION_HOST_WARMUP_SECONDS'])->toBeGreaterThanOrEqual(30);

    $download = array_values(array_filter($compare['steps'], static fn(array $step): bool => isset($step['with']['pattern'])));
    expect($download)->toHaveCount(1)
        ->and($download[0]['with']['pattern'])->toBe('foundation4-pair-${{ matrix.batch }}-*');

    $run = implode("\n", array_column($compare['steps'], 'run'));
    expect($run)->toContain('foundation4-host-merge.php', 'foundation4-module-cli-compare.php')
        ->not->toContain('|| true', 'continue-on-error');
});

it('captures benchmark configuration using only options supported by its PHP version', function (bool $php85): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root . '/.github/workflows/foundation4-host-certification.yml');
    $steps = array_column($workflow['jobs']['paired-host']['steps'], 'run', 'name');
    $directory = sys_get_temp_dir() . '/foundation-ini-capture-' . bin2hex(random_bytes(8));
    mkdir($directory . '/bin', 0700, true);
    mkdir($directory . '/locked-dependencies', 0700);
    file_put_contents($directory . '/locked-dependencies/composer.lock', '{}');
    $wrapper = "#!/bin/sh\n"
        . 'if [ "$1" = "-r" ]; then case "$2" in *PHP_VERSION_ID*) exit ' . ($php85 ? '0' : '1') . ';; esac; fi' . "\n"
        . 'if [ "$1" = "--ini=diff" ]; then ' . ($php85 ? 'echo enabled; exit 0;' : 'echo unsupported >&2; exit 64;') . ' fi' . "\n"
        . 'exec ' . escapeshellarg(PHP_BINARY) . ' "$@"' . "\n";
    file_put_contents($directory . '/bin/php', $wrapper);
    chmod($directory . '/bin/php', 0700);
    $process = new \Symfony\Component\Process\Process([
        'bash', '-e', '-o', 'pipefail', '-c', $steps['Capture exact runtime and dependency identity'],
    ], $directory, ['PATH' => $directory . '/bin:' . getenv('PATH')]);

    try {
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_exists($directory . '/evidence/php-ini-files.txt'))->toBeTrue()
            ->and(file_exists($directory . '/evidence/php-ini-diff.txt'))->toBe($php85);
        $settings = json_decode((string) file_get_contents($directory . '/evidence/php-runtime-settings.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($settings)->toHaveKeys(['opcache.enable_cli', 'opcache.validate_timestamps', 'memory_limit']);
    } finally {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
})->with([false, true]);

it('keeps the benchmark controller and its telemetry children on the load CPU', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = Yaml::parseFile($root . '/.github/workflows/foundation4-host-certification.yml');
    $steps = array_column($workflow['jobs']['paired-host']['steps'], 'run', 'name');
    expect(preg_match('/^Cpus_allowed_list:\s*(\d+)/m', (string) file_get_contents('/proc/self/status'), $matches))->toBe(1);
    $cpu = $matches[1];
    $directory = sys_get_temp_dir() . '/foundation-controller-affinity-' . bin2hex(random_bytes(8));
    $probe = <<<'SH'
    set -euo pipefail
    mkdir -p "$FOUNDATION_HOST_RESULT_DIR"
    awk '/^Cpus_allowed_list:/ { print $2 }' "/proc/$$/status" > "$FOUNDATION_HOST_RESULT_DIR/controller.txt"
    php -r 'preg_match("/^Cpus_allowed_list:\\s*(.+)$/m", file_get_contents("/proc/self/status"), $m); echo trim($m[1]), PHP_EOL;' > "$FOUNDATION_HOST_RESULT_DIR/child.txt"
    SH;

    try {
        foreach (['baseline', 'candidate'] as $variant) {
            mkdir($directory . '/' . $variant . '/benchmarks', 0700, true);
            file_put_contents($directory . '/' . $variant . '/benchmarks/foundation4-host-certify.sh', $probe);
        }
        $process = new \Symfony\Component\Process\Process([
            'bash', '-e', '-o', 'pipefail', '-c', $steps['Run baseline and candidate on this same physical runner'],
        ], $directory, [
            'GITHUB_WORKSPACE' => $directory,
            'BASELINE_SHA' => str_repeat('1', 40),
            'CANDIDATE_SHA' => str_repeat('2', 40),
            'MEASUREMENT_BATCH' => 'primary',
            'FOUNDATION_HOST_LOAD_CPU_SET' => $cpu,
            'FOUNDATION_HOST_FILTER_HOST' => 'runwire',
            'FOUNDATION_HOST_FILTER_WORKERS' => '4',
            'FOUNDATION_HOST_FILTER_CONCURRENCY' => '10',
        ]);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        foreach (['baseline', 'candidate'] as $variant) {
            foreach (['controller', 'child'] as $role) {
                expect(trim((string) file_get_contents($directory . '/evidence/' . $variant . '/' . $role . '.txt')))->toBe($cpu);
            }
        }
    } finally {
        (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
    }
});
