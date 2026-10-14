<?php

declare(strict_types=1);

/** @return array{requests:int,successful:int,failed:int,rpm:float,duration_seconds:float,p50_ms:float,p95_ms:float,p99_ms:float} */
function f4HostLoad(string $path, int $expectedRequests): array
{
    $result = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($result)
        || ($result['protocol'] ?? null) !== 'HTTP/1.1'
        || ($result['requests'] ?? null) !== $expectedRequests
        || ($result['successful'] ?? null) !== $expectedRequests
        || ($result['failed'] ?? null) !== 0
    ) {
        throw new RuntimeException(sprintf('Invalid response-verified host load: %s', $path));
    }

    return [
        'requests' => (int) $result['requests'],
        'successful' => (int) $result['successful'],
        'failed' => (int) $result['failed'],
        'rpm' => (float) $result['rpm'],
        'duration_seconds' => (float) $result['duration_seconds'],
        'p50_ms' => (float) $result['p50_ms'],
        'p95_ms' => (float) $result['p95_ms'],
        'p99_ms' => (float) $result['p99_ms'],
    ];
}

/** @return array{peak_rss_mb:float,peak_process_cpu_percent:float,peak_processes:int,samples:int} */
function f4HostSamples(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) < 2) {
        throw new RuntimeException(sprintf('No live process samples: %s', $path));
    }

    $maxRssKb = 0.0;
    $maxCpu = 0.0;
    $maxProcesses = 0;
    foreach (array_slice($lines, 1) as $line) {
        $values = str_getcsv($line);
        if (count($values) !== 4) {
            throw new RuntimeException(sprintf('Invalid process sample: %s', $line));
        }
        $maxRssKb = max($maxRssKb, (float) $values[1]);
        $maxCpu = max($maxCpu, (float) $values[2]);
        $maxProcesses = max($maxProcesses, (int) $values[3]);
    }
    if ($maxProcesses === 0 || $maxRssKb === 0.0) {
        throw new RuntimeException('Process-tree memory telemetry is missing.');
    }

    return [
        'peak_rss_mb' => round($maxRssKb / 1024, 2),
        'peak_process_cpu_percent' => round($maxCpu, 2),
        'peak_processes' => $maxProcesses,
        'samples' => count($lines) - 1,
    ];
}

/** @param list<float> $values */
function f4HostMedian(array $values): float
{
    sort($values, SORT_NUMERIC);
    $n = count($values);
    if ($n === 0) {
        throw new RuntimeException('Cannot summarize an empty benchmark series.');
    }

    return $n % 2 === 1
        ? $values[intdiv($n, 2)]
        : ($values[intdiv($n, 2) - 1] + $values[intdiv($n, 2)]) / 2;
}

$root = getenv('FOUNDATION_HOST_RESULT_DIR') ?: dirname(__DIR__) . '/build/foundation4-host';
$requests = (int) (getenv('FOUNDATION_HOST_REQUESTS') ?: 900);
$repeats = (int) (getenv('FOUNDATION_HOST_REPEATS') ?: 3);
$result = [
    'schema' => 2,
    'classification' => 'preliminary GitHub-runner host evidence; no production-equivalent baseline comparison',
    'sha' => getenv('FOUNDATION_HOST_SOURCE_SHA') ?: (getenv('GITHUB_SHA') ?: 'working-tree'),
    // Explicit operator-controlled identity. GitHub-hosted runners remain
    // non-certifiable until a physically matched baseline/candidate exists.
    'hardware_id' => getenv('FOUNDATION_CERTIFICATION_HOST_ID') ?: null,
    'workload_profile' => getenv('FOUNDATION_HOST_WORKLOAD_PROFILE') ?: 'json-minimal',
    'composer_lock_sha256' => is_file(dirname(__DIR__) . '/composer.lock')
        ? hash_file('sha256', dirname(__DIR__) . '/composer.lock')
        : null,
    'php' => PHP_VERSION,
    'request_count' => $requests,
    'repetitions' => $repeats,
    'endpoint' => '/json',
    'protocol' => 'HTTP/1.1 with per-response status/body checks',
    'hosts' => [],
];

foreach (['fpm', 'runwire'] as $host) {
    foreach ([1, 4] as $workers) {
        foreach ([1, 10, 50] as $concurrency) {
            $runs = [];
            $rpms = [];
            for ($i = 1; $i <= $repeats; $i++) {
                $prefix = sprintf('%s-w%d-c%d-r%d', $host, $workers, $concurrency, $i);
                $ab = f4HostLoad($root . '/' . $prefix . '.load.json', $requests);
                $telemetry = f4HostSamples($root . '/' . $prefix . '.samples.csv');
                $runs[] = [...$ab, ...$telemetry];
                $rpms[] = $ab['rpm'];
            }
            $result['hosts'][$host][] = [
                'workers' => $workers,
                'concurrency' => $concurrency,
                'median_successful_rpm' => round(f4HostMedian($rpms), 2),
                'runs' => $runs,
            ];
        }
    }
}

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (file_put_contents($root . '/report.json', $json, LOCK_EX) === false) {
    throw new RuntimeException('Unable to write host benchmark evidence.');
}
fwrite(STDOUT, $json);
