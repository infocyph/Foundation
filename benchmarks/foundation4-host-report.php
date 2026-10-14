<?php

declare(strict_types=1);

/** @return float|int */
function f4HostField(string $source, string $pattern, string $label): float|int
{
    if (preg_match($pattern, $source, $matches) !== 1) {
        throw new RuntimeException(sprintf('Missing %s in ApacheBench output.', $label));
    }

    return str_contains($matches[1], '.') ? (float) $matches[1] : (int) $matches[1];
}

/** @return array{requests:int,failed:int,non_2xx:int,rpm:float,p50_ms:float,p95_ms:float,p99_ms:float} */
function f4HostApacheBench(string $path, int $expectedRequests): array
{
    $text = file_get_contents($path);
    if (!is_string($text) || $text === '') {
        throw new RuntimeException(sprintf('Missing benchmark output: %s', $path));
    }

    $requests = (int) f4HostField($text, '/^Complete requests:\s+(\d+)/m', 'requests');
    $failed = (int) f4HostField($text, '/^Failed requests:\s+(\d+)/m', 'failures');
    $non2xx = preg_match('/^Non-2xx responses:\s+(\d+)/m', $text, $matches) === 1
        ? (int) $matches[1]
        : 0;
    $length = (int) f4HostField($text, '/^Document Length:\s+(\d+)\s+bytes/m', 'document length');
    $duration = (float) f4HostField($text, '/^Time taken for tests:\s+([\d.]+)\s+seconds/m', 'duration');
    if ($requests !== $expectedRequests || $failed !== 0 || $non2xx !== 0 || $length !== 11 || $duration <= 0) {
        throw new RuntimeException(sprintf(
            '%s: invalid requests=%d failures=%d non_2xx=%d length=%d duration=%f',
            $path,
            $requests,
            $failed,
            $non2xx,
            $length,
            $duration,
        ));
    }

    return [
        'requests' => $requests,
        'failed' => $failed,
        'non_2xx' => $non2xx,
        'rpm' => round($requests * 60 / $duration, 2),
        'p50_ms' => (float) f4HostField($text, '/^\s*50%\s+([\d.]+)/m', 'p50'),
        'p95_ms' => (float) f4HostField($text, '/^\s*95%\s+([\d.]+)/m', 'p95'),
        'p99_ms' => (float) f4HostField($text, '/^\s*99%\s+([\d.]+)/m', 'p99'),
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
    'schema' => 1,
    'classification' => 'preliminary GitHub-runner host evidence; no production-equivalent baseline comparison',
    'sha' => getenv('GITHUB_SHA') ?: 'working-tree',
    'php' => PHP_VERSION,
    'request_count' => $requests,
    'repetitions' => $repeats,
    'endpoint' => '/json',
    'hosts' => [],
];

foreach (['fpm', 'runwire'] as $host) {
    foreach ([1, 4] as $workers) {
        foreach ([1, 10, 50] as $concurrency) {
            $runs = [];
            $rpms = [];
            for ($i = 1; $i <= $repeats; $i++) {
                $prefix = sprintf('%s-w%d-c%d-r%d', $host, $workers, $concurrency, $i);
                $ab = f4HostApacheBench($root . '/' . $prefix . '.ab.txt', $requests);
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
