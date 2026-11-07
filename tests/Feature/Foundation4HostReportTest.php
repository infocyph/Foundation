<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('distinguishes CPU used within the sample window from lifetime CPU gauges', function (bool $singleSample): void {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/foundation-host-report-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $load = [
        'protocol' => 'HTTP/1.1', 'requests' => 100, 'successful' => 100, 'failed' => 0,
        'concurrency' => 1, 'connection_warmup' => ['successful' => 1],
        'measured_new_connections' => 0, 'load_cpu_percent' => 7.5, 'worker_requests' => [123 => 100],
        'database_queries' => 400, 'application_cache_hits' => 100, 'authenticated' => true,
        'rpm' => 1500, 'duration_seconds' => 4, 'p50_ms' => 1, 'p95_ms' => 2, 'p99_ms' => 3,
    ];
    $samples = "timestamp,rss_kb,lifetime_cpu_percent,processes,cpu_seconds\n100,1024,99,1,10\n";
    if (!$singleSample) {
        $samples .= "102,2048,90,1,11\n104,1024,80,1,12\n";
    }

    try {
        foreach ([1, 2] as $repetition) {
            $prefix = $directory . '/fpm-w1-c1-r' . $repetition;
            file_put_contents($prefix . '.load.json', json_encode($load, JSON_THROW_ON_ERROR));
            file_put_contents($prefix . '.samples.csv', $samples);
        }
        $process = new Process([
            PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr',
            $root . '/benchmarks/foundation4-host-report.php',
        ], $root, [
            'FOUNDATION_HOST_RESULT_DIR' => $directory,
            'FOUNDATION_HOST_WORKLOAD_PROFILE' => 'authenticated-read',
            'FOUNDATION_HOST_FILTER_HOST' => 'fpm',
            'FOUNDATION_HOST_FILTER_WORKERS' => '1',
            'FOUNDATION_HOST_FILTER_CONCURRENCY' => '1',
            'FOUNDATION_HOST_REQUESTS' => '100',
            'FOUNDATION_HOST_REPEATS' => '2',
            'FOUNDATION_HOST_SOAK_SECONDS' => '0',
        ]);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        expect($process->getErrorOutput())->toBe('');
        $report = json_decode((string) file_get_contents($directory . '/report.json'), true, flags: JSON_THROW_ON_ERROR);
        $run = $report['hosts']['fpm'][0]['runs'][0];

        expect($run['mean_process_cpu_percent'])->toBe($singleSample ? null : 50)
            ->and($run['peak_lifetime_process_cpu_percent'])->toBe(99)
            ->and($run['cpu_counter_resolution_seconds'])->toBe(1)
            ->and($run['load_cpu_percent'])->toBe(7.5)
            ->and($run['samples'])->toBe($singleSample ? 1 : 3);
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([false, true]);
