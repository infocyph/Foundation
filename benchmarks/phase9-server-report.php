<?php

declare(strict_types=1);

/** @return list<float> */
function phase9ServerFloatLines(string $path): array
{
    $contents = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($contents) || $contents === []) {
        throw new RuntimeException(sprintf('Phase 9 server evidence "%s" is empty.', $path));
    }

    return array_map(static fn(string $value): float => (float) trim($value), $contents);
}

function phase9ServerPercentile(array $values, float $percentile): float
{
    sort($values, SORT_NUMERIC);
    $index = max(0, min(count($values) - 1, (int) ceil(count($values) * $percentile) - 1));

    return (float) $values[$index];
}

/** @return array<string,mixed> */
function phase9ServerAb(string $path): array
{
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        throw new RuntimeException(sprintf('Phase 9 ApacheBench evidence "%s" is empty.', $path));
    }

    $field = static function (string $pattern, string $name) use ($contents): string {
        if (preg_match($pattern, $contents, $matches) !== 1) {
            throw new RuntimeException(sprintf('Phase 9 ApacheBench output is missing "%s".', $name));
        }

        return $matches[1];
    };

    $latency = [];
    foreach ([50, 95, 99] as $percentile) {
        $latency['p' . $percentile] = (float) $field(
            '/^\s*' . $percentile . '%\s+(\d+(?:\.\d+)?)\s*$/m',
            'p' . $percentile,
        );
    }

    return [
        'complete_requests' => (int) $field('/^Complete requests:\s+(\d+)\s*$/m', 'complete requests'),
        'failed_requests' => (int) $field('/^Failed requests:\s+(\d+)\s*$/m', 'failed requests'),
        'requests_per_second' => (float) $field(
            '/^Requests per second:\s+([0-9.]+)\s+\[#\/sec\]/m',
            'requests per second',
        ),
        'latency_ms' => $latency,
    ];
}

/** @return array<string,mixed> */
function phase9ServerResult(string $root, string $server): array
{
    $ab = phase9ServerAb($root . '/' . $server . '-ab.txt');
    if ($ab['failed_requests'] !== 0) {
        throw new RuntimeException(sprintf('Phase 9 %s benchmark contained failed requests.', $server));
    }

    $coldSeconds = phase9ServerFloatLines($root . '/' . $server . '-cold.txt');
    $warmSeconds = phase9ServerFloatLines($root . '/' . $server . '-warm.txt');
    $rssKb = phase9ServerFloatLines($root . '/' . $server . '-rss-kb.txt');

    return [
        'cold_first_request_ms' => round($coldSeconds[0] * 1_000, 3),
        'warm_sequential_ms' => [
            'p50' => round(phase9ServerPercentile($warmSeconds, 0.50) * 1_000, 3),
            'p95' => round(phase9ServerPercentile($warmSeconds, 0.95) * 1_000, 3),
            'p99' => round(phase9ServerPercentile($warmSeconds, 0.99) * 1_000, 3),
        ],
        'concurrent' => $ab,
        'fpm_rss' => [
            'sample_count' => count($rssKb),
            'peak_mb' => round(max($rssKb) / 1_024, 3),
            'final_mb' => round($rssKb[array_key_last($rssKb)] / 1_024, 3),
        ],
    ];
}

$repository = dirname(__DIR__);
$root = getenv('PHASE9_SERVER_RESULT_DIR') ?: $repository . '/build/phase9-server-results';
$opcache = json_decode((string) file_get_contents($root . '/opcache.json'), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($opcache) || ($opcache['opcache_enabled'] ?? false) !== true || ($opcache['sapi'] ?? null) !== 'fpm-fcgi') {
    throw new RuntimeException('Phase 9 real-server benchmark requires OPcache-enabled PHP-FPM.');
}

$result = [
    'schema' => 1,
    'captured_at_utc' => gmdate(DATE_ATOM),
    'source' => [
        'foundation_commit' => getenv('GITHUB_SHA') ?: 'working-tree',
    ],
    'runtime' => [
        'fpm' => trim((string) file_get_contents($root . '/fpm-version.txt')),
        'nginx' => trim((string) file_get_contents($root . '/nginx-version.txt')),
        'apache' => trim((string) file_get_contents($root . '/apache-version.txt')),
        'opcache' => $opcache,
    ],
    'configuration' => [
        'endpoint' => '/json',
        'response' => '{"ok":true}',
        'apachebench_requests' => (int) (getenv('PHASE9_SERVER_REQUESTS') ?: 20_000),
        'apachebench_concurrency' => (int) (getenv('PHASE9_SERVER_CONCURRENCY') ?: 32),
        'warm_sequential_samples' => 30,
    ],
    'nginx' => phase9ServerResult($root, 'nginx'),
    'apache' => phase9ServerResult($root, 'apache'),
];

$output = getenv('PHASE9_SERVER_OUTPUT') ?: $repository . '/build/phase9-server-benchmark.json';
file_put_contents(
    $output,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    LOCK_EX,
);
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
