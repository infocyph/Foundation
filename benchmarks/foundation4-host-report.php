<?php

declare(strict_types=1);

/** @return array{requests:int,successful:int,failed:int,rpm:float,duration_seconds:float,p50_ms:float,p95_ms:float,p99_ms:float} */
function f4HostLoad(string $path, int $expectedRequests): array
{
    $result = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($result)
        || ($result['protocol'] ?? null) !== 'HTTP/1.1'
        || !is_int($result['requests'] ?? null)
        || $result['requests'] < $expectedRequests
        || ($result['successful'] ?? null) !== $result['requests']
        || ($result['failed'] ?? null) !== 0
        || !is_array($result['connection_warmup'] ?? null)
        || ($result['connection_warmup']['successful'] ?? null) !== ($result['concurrency'] ?? null)
        || !is_int($result['measured_new_connections'] ?? null)
        || $result['measured_new_connections'] < 0
        || !is_array($result['worker_requests'] ?? null)
        || (!is_int($result['load_cpu_percent'] ?? null) && !is_float($result['load_cpu_percent'] ?? null))
        || !is_finite((float) $result['load_cpu_percent'])
        || $result['load_cpu_percent'] < 0
    ) {
        throw new RuntimeException(sprintf('Invalid response-verified host load: %s', $path));
    }
    if (($result['authenticated'] ?? false) && array_sum($result['worker_requests']) !== $result['successful']) {
        throw new RuntimeException('Authenticated worker telemetry does not cover every successful response.');
    }

    return [
        'requests' => (int) $result['requests'],
        'connection_warmup' => $result['connection_warmup'],
        'measured_new_connections' => $result['measured_new_connections'],
        'load_cpu_percent' => $result['load_cpu_percent'],
        'worker_requests' => $result['worker_requests'],
        'database_queries' => (int) ($result['database_queries'] ?? 0),
        'application_cache_hits' => (int) ($result['application_cache_hits'] ?? 0),
        'authenticated' => ($result['authenticated'] ?? false) === true,
        'successful' => (int) $result['successful'],
        'failed' => (int) $result['failed'],
        'rpm' => (float) $result['rpm'],
        'duration_seconds' => (float) $result['duration_seconds'],
        'p50_ms' => (float) $result['p50_ms'],
        'p95_ms' => (float) $result['p95_ms'],
        'p99_ms' => (float) $result['p99_ms'],
    ];
}

/** @return array<string,int|float|null> */
function f4HostSamples(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) < 2) {
        throw new RuntimeException(sprintf('No live process samples: %s', $path));
    }

    $maxRssKb = 0.0;
    $firstRssKb = null;
    $lastRssKb = 0.0;
    $maxCpu = 0.0;
    $maxProcesses = 0;
    $firstTime = $lastTime = $firstCpuSeconds = $lastCpuSeconds = null;
    foreach (array_slice($lines, 1) as $line) {
        $values = str_getcsv($line, escape: '');
        if (count($values) !== 5) {
            throw new RuntimeException(sprintf('Invalid process sample: %s', $line));
        }
        $rssKb = (float) $values[1];
        $firstRssKb ??= $rssKb;
        $lastRssKb = $rssKb;
        $maxRssKb = max($maxRssKb, $rssKb);
        $maxCpu = max($maxCpu, (float) $values[2]);
        $maxProcesses = max($maxProcesses, (int) $values[3]);
        $firstTime ??= (float) $values[0];
        $lastTime = (float) $values[0];
        $firstCpuSeconds ??= (float) $values[4];
        $lastCpuSeconds = (float) $values[4];
    }
    if ($maxProcesses === 0 || $maxRssKb === 0.0 || $lastTime < $firstTime || $lastCpuSeconds < $firstCpuSeconds) {
        throw new RuntimeException('Process-tree memory or CPU-window telemetry is invalid.');
    }

    return [
        'first_rss_mb' => round((float) $firstRssKb / 1024, 2),
        'last_rss_mb' => round($lastRssKb / 1024, 2),
        'peak_rss_mb' => round($maxRssKb / 1024, 2),
        'peak_lifetime_process_cpu_percent' => round($maxCpu, 2),
        'mean_process_cpu_percent' => $lastTime > $firstTime
            ? round(($lastCpuSeconds - $firstCpuSeconds) / ($lastTime - $firstTime) * 100, 2)
            : null,
        'cpu_counter_resolution_seconds' => 1,
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
    'tool_sha' => getenv('FOUNDATION_HOST_TOOL_SHA') ?: null,
    'fixture_id' => getenv('FOUNDATION_HOST_WORKLOAD_PROFILE') === 'authenticated-read' ? 'foundation-auth-postgres-redis-v2-warm-connections' : null,
    'composer_lock_sha256' => is_file(dirname(__DIR__) . '/composer.lock')
        ? hash_file('sha256', dirname(__DIR__) . '/composer.lock')
        : null,
    'php' => PHP_VERSION,
    'request_count' => $requests,
    'repetitions' => $repeats,
    'warmup_seconds' => (int) (getenv('FOUNDATION_HOST_WARMUP_SECONDS') ?: 0),
    'soak_window_seconds' => (int) (getenv('FOUNDATION_HOST_SOAK_SECONDS') ?: 0),
    'endpoint' => match (getenv('FOUNDATION_HOST_WORKLOAD_PROFILE')) {
        'native-read' => '/native',
        'authenticated-read' => '/authenticated',
        default => '/json',
    },
    'protocol' => 'HTTP/1.1 with per-response status/body checks',
    'hosts' => [],
];

foreach (getenv('FOUNDATION_HOST_FILTER_HOST') ? [getenv('FOUNDATION_HOST_FILTER_HOST')] : ['fpm', 'runwire'] as $host) {
    foreach (getenv('FOUNDATION_HOST_FILTER_WORKERS') ? [(int) getenv('FOUNDATION_HOST_FILTER_WORKERS')] : [1, 4] as $workers) {
        foreach (getenv('FOUNDATION_HOST_FILTER_CONCURRENCY') ? [(int) getenv('FOUNDATION_HOST_FILTER_CONCURRENCY')] : [1, 10, 50] as $concurrency) {
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

$soakSeconds = $result['soak_window_seconds'];
if ($soakSeconds > 0) {
    if ($soakSeconds < 30 || $soakSeconds > 300) {
        throw new RuntimeException('Invalid sustained host soak duration.');
    }

    $result['soak'] = [];
    foreach (getenv('FOUNDATION_HOST_FILTER_HOST') ? [getenv('FOUNDATION_HOST_FILTER_HOST')] : ['fpm', 'runwire'] as $host) {
        $windows = [];
        foreach ([1, 2] as $period) {
            $prefix = sprintf('%s-soak-w4-c10-p%d', $host, $period);
            $load = f4HostLoad($root . '/' . $prefix . '.load.json', $requests);
            if ($load['duration_seconds'] < $soakSeconds) {
                throw new RuntimeException(sprintf('Insufficient %s soak duration.', $host));
            }
            $windows[] = [...$load, ...f4HostSamples($root . '/' . $prefix . '.samples.csv')];
        }
        $result['soak'][$host] = [
            'workers' => 4,
            'concurrency' => 10,
            'windows' => $windows,
            'status' => 'correct_responses_only_not_production_certification',
        ];
    }
}

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (file_put_contents($root . '/report.json', $json, LOCK_EX) === false) {
    throw new RuntimeException('Unable to write host benchmark evidence.');
}
fwrite(STDOUT, $json);
