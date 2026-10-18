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
