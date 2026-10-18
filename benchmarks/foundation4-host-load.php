<?php

declare(strict_types=1);

if ($argc !== 5) {
    throw new InvalidArgumentException('Usage: php foundation4-host-load.php URL REQUESTS CONCURRENCY OUTPUT');
}
[, $url, $requested, $parallel, $output] = $argv;
$requests = (int) $requested;
$concurrency = (int) $parallel;
$minimumSeconds = (float) (getenv('FOUNDATION_HOST_MIN_SECONDS') ?: 0);
if (!is_finite($minimumSeconds) || $minimumSeconds < 0 || $minimumSeconds > 300) {
    throw new InvalidArgumentException('Sustained benchmark duration must be 0-300 seconds.');
}
$authorization = getenv('FOUNDATION_HOST_AUTHORIZATION') ?: '';
$authenticated = getenv('FOUNDATION_HOST_WORKLOAD_PROFILE') === 'authenticated-read';
$totalQueries = 0;
$totalCacheHits = 0;
$minimumNanoseconds = (int) ($minimumSeconds * 1_000_000_000);
if ($requests < 100 || $concurrency < 1 || $concurrency > 100 || !str_starts_with($url, 'http://127.0.0.1:')) {
    throw new InvalidArgumentException('Invalid local HTTP load parameters.');
}
if (!extension_loaded('curl')) {
    throw new RuntimeException('Native PHP cURL extension is required for the HTTP/1.1 load harness.');
}

$multi = curl_multi_init();
curl_multi_setopt($multi, CURLMOPT_MAXCONNECTS, $concurrency);
$handles = [];
$running = 0;
$sent = 0;
$finished = 0;
$errors = 0;
$latencies = [];
$firstError = null;
$workerRequests = [];
$newConnections = 0;

try {
    for ($i = 0; $i < min($concurrency, $requests); $i++) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => $authenticated,
            CURLOPT_HTTPHEADER => $authorization === '' ? [] : ['Authorization: ' . $authorization],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => 'identity',
        ]);
        $handles[] = $handle;
        $sent++;
    }

    // Warm distinct connections gradually; a cold burst can all reach one prefork worker.
    $connectionWarmup = f4HostPrimeConnections($multi, $handles, $authenticated);
    foreach ($handles as $handle) {
        curl_multi_add_handle($multi, $handle);
    }
    $cpuStarted = getrusage();
    $start = hrtime(true);

    while ($finished < $sent || $sent < $requests || hrtime(true) - $start < $minimumNanoseconds) {
        do {
            $state = curl_multi_exec($multi, $running);
        } while ($state === CURLM_CALL_MULTI_PERFORM);
        if ($state !== CURLM_OK) {
            throw new RuntimeException(sprintf('cURL multi failed: %d', $state));
        }

        while (($message = curl_multi_info_read($multi)) !== false) {
            $handle = $message['handle'];
            $response = f4HostResponse($handle, $message['result'], $authenticated);
            $newConnections += (int) curl_getinfo($handle, CURLINFO_NUM_CONNECTS);
            if ($response['error'] !== null) {
                $errors++;
                $firstError ??= $response['error'];
            } else {
                $latencies[] = $response['latency_ms'];
                $totalQueries += $response['queries'];
                $totalCacheHits += $response['cache_hits'];
                if ($authenticated) {
                    $pid = $response['worker'];
                    $workerRequests[$pid] = ($workerRequests[$pid] ?? 0) + 1;
                }
            }

            curl_multi_remove_handle($multi, $handle);
            $finished++;
            if ($sent < $requests || hrtime(true) - $start < $minimumNanoseconds) {
                if ($sent >= 1_000_000) {
                    throw new RuntimeException('Load sample limit reached before sustained interval completed.');
                }
                curl_multi_add_handle($multi, $handle);
                $sent++;
            }
        }

        if ($running > 0) {
            $ready = curl_multi_select($multi, 0.2);
            if ($ready === -1) {
                usleep(1000);
            }
        }
    }
} finally {
    foreach ($handles as $handle) {
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);
}

$duration = max((hrtime(true) - $start) / 1_000_000_000, 0.000001);
$cpuFinished = getrusage();
$cpuSeconds = ($cpuFinished['ru_utime.tv_sec'] - $cpuStarted['ru_utime.tv_sec'])
    + ($cpuFinished['ru_stime.tv_sec'] - $cpuStarted['ru_stime.tv_sec'])
    + ($cpuFinished['ru_utime.tv_usec'] - $cpuStarted['ru_utime.tv_usec']) / 1_000_000
    + ($cpuFinished['ru_stime.tv_usec'] - $cpuStarted['ru_stime.tv_usec']) / 1_000_000;
sort($latencies, SORT_NUMERIC);
$percentile = static function (float $fraction) use ($latencies): float {
    $index = max(0, min(count($latencies) - 1, (int) ceil(count($latencies) * $fraction) - 1));

    return round($latencies[$index] ?? 0.0, 3);
};

$record = [
    'protocol' => 'HTTP/1.1',
    'requests' => $finished,
    'required_requests' => $requests,
    'minimum_duration_seconds' => $minimumSeconds,
    'connection_warmup' => $connectionWarmup,
    'measured_new_connections' => $newConnections,
    'load_cpu_percent' => round($cpuSeconds / $duration * 100, 3),
    'worker_requests' => $workerRequests,
    'database_queries' => $totalQueries,
    'application_cache_hits' => $totalCacheHits,
    'authenticated' => $authenticated,
    'successful' => count($latencies),
    'failed' => $errors,
    'concurrency' => $concurrency,
    'duration_seconds' => round($duration, 6),
    'rpm' => round(count($latencies) * 60 / $duration, 2),
    'p50_ms' => $percentile(0.5),
    'p95_ms' => $percentile(0.95),
    'p99_ms' => $percentile(0.99),
    'first_error' => $firstError,
];
$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (file_put_contents($output, $json, LOCK_EX) === false) {
    throw new RuntimeException('Unable to persist HTTP/1.1 load evidence.');
}
fwrite(STDOUT, $json);
if ($errors !== 0 || $finished < $requests || $duration < $minimumSeconds) {
    throw new RuntimeException(sprintf('HTTP load failed: %s', $firstError ?? 'request count mismatch'));
}

/** @return array{error:?string,latency_ms:float,queries:int,cache_hits:int,worker:int} */
function f4HostResponse(CurlHandle $handle, int $result, bool $authenticated): array
{
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $body = (string) curl_multi_getcontent($handle);
    $queries = $cacheHits = $worker = 0;
    if ($authenticated) {
        $headerBytes = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headers = substr($body, 0, $headerBytes);
        $body = substr($body, $headerBytes);
        preg_match('/^X-Foundation-Benchmark-Queries:\s*(\d+)\s*$/im', $headers, $queryMatch);
        preg_match('/^X-Foundation-Benchmark-Cache-Hits:\s*(\d+)\s*$/im', $headers, $cacheMatch);
        preg_match('/^X-Foundation-Benchmark-Worker:\s*(\d+)\s*$/im', $headers, $workerMatch);
        $queries = (int) ($queryMatch[1] ?? 0);
        $cacheHits = (int) ($cacheMatch[1] ?? 0);
        $worker = (int) ($workerMatch[1] ?? 0);
        if ($queries < 2 || $cacheHits !== 1 || $worker <= 0) {
            $body = 'missing authenticated query/cache/worker evidence';
        }
    }

    return [
        'error' => $result === CURLE_OK && $status === 200 && $body === '{"ok":true}'
            ? null
            : sprintf('status=%d curl=%d body=%s', $status, $result, substr($body, 0, 120)),
        'latency_ms' => (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000,
        'queries' => $queries,
        'cache_hits' => $cacheHits,
        'worker' => $worker,
    ];
}

/**
 * @param list<CurlHandle> $handles
 * @return array{successful:int,duration_seconds:float,worker_requests:array<int,int>}
 */
function f4HostPrimeConnections(CurlMultiHandle $multi, array $handles, bool $authenticated): array
{
    $started = hrtime(true);
    $completed = 0;
    $workers = [];
    $pump = static function () use ($multi, $authenticated, &$completed, &$workers): int {
        do {
            $state = curl_multi_exec($multi, $running);
        } while ($state === CURLM_CALL_MULTI_PERFORM);
        if ($state !== CURLM_OK) {
            throw new RuntimeException('Connection warm-up multi failed: ' . $state);
        }
        while (($message = curl_multi_info_read($multi)) !== false) {
            $handle = $message['handle'];
            $response = f4HostResponse($handle, $message['result'], $authenticated);
            if ($response['error'] !== null) {
                throw new RuntimeException('Connection warm-up failed: ' . $response['error']);
            }
            curl_multi_remove_handle($multi, $handle);
            $completed++;
            if ($authenticated) {
                $pid = $response['worker'];
                $workers[$pid] = ($workers[$pid] ?? 0) + 1;
            }
        }

        return $running;
    };
    foreach ($handles as $handle) {
        curl_setopt($handle, CURLOPT_FRESH_CONNECT, true);
        curl_multi_add_handle($multi, $handle);
        $next = hrtime(true) + 5_000_000;
        do {
            $running = $pump();
            $remaining = ($next - hrtime(true)) / 1_000_000_000;
            if ($remaining > 0 && ($running === 0 || curl_multi_select($multi, $remaining) === -1)) {
                usleep((int) ceil($remaining * 1_000_000));
            }
        } while (hrtime(true) < $next);
    }
    while ($completed < count($handles)) {
        $pump();
        if ($completed < count($handles) && curl_multi_select($multi, 0.05) === -1) {
            usleep(1000);
        }
    }
    foreach ($handles as $handle) {
        curl_setopt($handle, CURLOPT_FRESH_CONNECT, false);
    }

    return [
        'successful' => $completed,
        'duration_seconds' => round((hrtime(true) - $started) / 1_000_000_000, 6),
        'worker_requests' => $workers,
    ];
}
