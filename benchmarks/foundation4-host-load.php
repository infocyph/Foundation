<?php

declare(strict_types=1);

if ($argc !== 5) {
    throw new InvalidArgumentException('Usage: php foundation4-host-load.php URL REQUESTS CONCURRENCY OUTPUT');
}
[, $url, $requested, $parallel, $output] = $argv;
$requests = (int) $requested;
$concurrency = (int) $parallel;
if ($requests < 100 || $concurrency < 1 || $concurrency > 100 || !str_starts_with($url, 'http://127.0.0.1:')) {
    throw new InvalidArgumentException('Invalid local HTTP load parameters.');
}
if (!extension_loaded('curl')) {
    throw new RuntimeException('Native PHP cURL extension is required for the HTTP/1.1 load harness.');
}

$multi = curl_multi_init();
$handles = [];
$running = 0;
$sent = 0;
$finished = 0;
$errors = 0;
$latencies = [];
$firstError = null;
$start = hrtime(true);

try {
    for ($i = 0; $i < min($concurrency, $requests); $i++) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => 'identity',
        ]);
        $handles[] = $handle;
        curl_multi_add_handle($multi, $handle);
        $sent++;
    }

    while ($finished < $requests) {
        do {
            $state = curl_multi_exec($multi, $running);
        } while ($state === CURLM_CALL_MULTI_PERFORM);
        if ($state !== CURLM_OK) {
            throw new RuntimeException(sprintf('cURL multi failed: %d', $state));
        }

        while (($message = curl_multi_info_read($multi)) !== false) {
            $handle = $message['handle'];
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($handle);
            $latencyMs = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
            $valid = $message['result'] === CURLE_OK && $status === 200 && $body === '{"ok":true}';

            if (!$valid) {
                $errors++;
                $firstError ??= sprintf(
                    'status=%d curl=%d body=%s',
                    $status,
                    $message['result'],
                    substr((string) $body, 0, 120),
                );
            } else {
                $latencies[] = $latencyMs;
            }

            curl_multi_remove_handle($multi, $handle);
            $finished++;
            if ($sent < $requests) {
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
sort($latencies, SORT_NUMERIC);
$percentile = static function (float $fraction) use ($latencies): float {
    $index = max(0, min(count($latencies) - 1, (int) ceil(count($latencies) * $fraction) - 1));

    return round($latencies[$index] ?? 0.0, 3);
};

$record = [
    'protocol' => 'HTTP/1.1',
    'requests' => $finished,
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
if ($errors !== 0 || $finished !== $requests) {
    throw new RuntimeException(sprintf('HTTP load failed: %s', $firstError ?? 'request count mismatch'));
}
