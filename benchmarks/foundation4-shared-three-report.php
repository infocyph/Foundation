<?php

declare(strict_types=1);

/**
 * Shared-workstation diagnostic for exactly the three non-reproducible
 * scenarios from the 24-pair hosted ARM certification. Never certifies release.
 */
if (count($argv) !== 3 || !is_dir($argv[1])) {
    throw new InvalidArgumentException('Usage: php foundation4-shared-three-report.php ARTIFACT_DIR OUTPUT.json');
}

$root = rtrim($argv[1], '/');
$expectedToolSha = getenv('FOUNDATION_HOST_TOOL_SHA');
if (!is_string($expectedToolSha) || preg_match('/\A[a-f0-9]{40}\z/D', $expectedToolSha) !== 1) {
    throw new RuntimeException('Missing immutable diagnostic tooling SHA.');
}

$scenarios = [
    ['id' => 'fpm-w1-c50', 'host' => 'fpm', 'workers' => 1, 'concurrency' => 50],
    ['id' => 'runwire-w1-c10', 'host' => 'runwire', 'workers' => 1, 'concurrency' => 10],
    ['id' => 'runwire-w4-c1', 'host' => 'runwire', 'workers' => 4, 'concurrency' => 1],
];
$results = [];
$seenHardware = null;

foreach ($scenarios as $scenario) {
    $entry = ['scenario' => $scenario['id']];
    foreach (['primary', 'confirmation'] as $batch) {
        $folder = $root . '/foundation4-shared-three-' . $scenario['id'] . '/' . $batch;
        if (trim((string) file_get_contents($folder . '/scope.txt')) !== 'diagnostic-only') {
            throw new RuntimeException('A certification-scoped artifact was not expected: ' . $folder);
        }
        $summaries = [];
        $fixture = null;
        $hardware = null;
        foreach (['baseline' => 'c220759fcbd6654fead843599712a5b79685f02d',
            'candidate' => '3fcebbfe06165a7363ea9e21ef55831469041fb3'] as $variant => $sha) {
            $report = json_decode((string) file_get_contents($folder . '/' . $variant . '/report.json'), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($report) || array_is_list($report)
                || ($report['schema'] ?? null) !== 3
                || ($report['sha'] ?? null) !== $sha
                || ($report['tool_sha'] ?? null) !== $expectedToolSha
                || ($report['composer_lock_sha256'] ?? null) !== '47714e7847c611f6cd8cef4caa45ac9ffae71188c93b7424d9daa867866f4ec7'
                || ($report['workload_profile'] ?? null) !== 'authenticated-read'
                || ($report['request_count'] ?? null) !== 1000
                || ($report['repetitions'] ?? null) !== 3
                || ($report['warmup_seconds'] ?? null) !== 120
                || !is_string($report['hardware_id'] ?? null)
                || $report['hardware_id'] === ''
                || !is_string($report['fixture_id'] ?? null)
            ) {
                throw new RuntimeException('Invalid immutable host evidence: ' . $folder . '/' . $variant);
            }
            if ($fixture !== null && $fixture !== $report['fixture_id']) {
                throw new RuntimeException('Authenticated workload fixture mismatch.');
            }
            if ($hardware !== null && $hardware !== $report['hardware_id']) {
                throw new RuntimeException('Baseline/candidate used different physical hosts.');
            }
            if ($seenHardware !== null && $seenHardware !== $report['hardware_id']) {
                throw new RuntimeException('The three diagnostics were not run on one booted host.');
            }
            $fixture = $report['fixture_id'];
            $hardware = $report['hardware_id'];
            $seenHardware = $hardware;
            $hosts = $report['hosts'] ?? null;
            $cases = is_array($hosts) && count($hosts) === 1 ? ($hosts[$scenario['host']] ?? null) : null;
            $case = is_array($cases) && count($cases) === 1 ? $cases[0] : null;
            if (!is_array($case) || ($case['workers'] ?? null) !== $scenario['workers']
                || ($case['concurrency'] ?? null) !== $scenario['concurrency']
                || !is_array($case['runs'] ?? null) || count($case['runs']) !== 3) {
                throw new RuntimeException('Incomplete or unexpected diagnostic scenario.');
            }
            $rpms = [];
            foreach ($case['runs'] as $run) {
                if (!is_array($run) || ($run['failed'] ?? null) !== 0
                    || ($run['authenticated'] ?? null) !== true
                    || !is_int($run['successful'] ?? null) || $run['successful'] < 1000
                    || !is_numeric($run['duration_seconds'] ?? null) || $run['duration_seconds'] < 120
                    || !is_numeric($run['rpm'] ?? null)
                    || !is_int($run['database_queries'] ?? null) || $run['database_queries'] < $run['successful']
                    || !is_int($run['application_cache_hits'] ?? null) || $run['application_cache_hits'] < $run['successful']) {
                    throw new RuntimeException('Missing sustained authenticated successful-request evidence.');
                }
                $rpm = 60.0 * $run['successful'] / $run['duration_seconds'];
                if (abs($rpm - (float) $run['rpm']) > $rpm * 0.005) {
                    throw new RuntimeException('Recorded RPM disagrees with successful requests and duration.');
                }
                $rpms[] = $rpm;
            }
            sort($rpms, SORT_NUMERIC);
            $mean = array_sum($rpms) / 3;
            $variance = array_sum(array_map(static fn (float $rpm): float => ($rpm - $mean) ** 2, $rpms)) / 3;
            $summaries[$variant] = ['median' => $rpms[1], 'cv' => sqrt($variance) / $mean * 100];
        }
        $delta = 100 * ($summaries['candidate']['median'] / $summaries['baseline']['median'] - 1);
        $cv = max($summaries['baseline']['cv'], $summaries['candidate']['cv']);
        $status = $cv > 2.5 ? 'inconclusive_variance' : ($delta < -2.0 ? 'regression_signal' : 'within_diagnostic_budget');
        $entry[$batch] = [
            'baseline_rpm' => round($summaries['baseline']['median'], 2),
            'candidate_rpm' => round($summaries['candidate']['median'], 2),
            'delta_percent' => round($delta, 3),
            'max_cv_percent' => round($cv, 3),
            'status' => $status,
        ];
    }
    $entry['regression_reproduced_in_both_orders'] =
        $entry['primary']['status'] === 'regression_signal' && $entry['confirmation']['status'] === 'regression_signal';
    $results[] = $entry;
}

$payload = [
    'scope' => 'diagnostic-only',
    'release_certified' => false,
    'source_run_id' => 43710493075,
    'source_sha' => '3fcebbfe06165a7363ea9e21ef55831469041fb3',
    'tool_sha' => $expectedToolSha,
    'runner' => 'foundation4-perf-01',
    'hardware_id' => $seenHardware,
    'trials_per_variant' => 3,
    'minimum_duration_seconds' => 120,
    'warmup_seconds' => 120,
    'reference_rpm_regression_limit_percent' => 2.0,
    'reference_cv_limit_percent' => 2.5,
    'scenarios' => $results,
];
if (file_put_contents($argv[2], json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . PHP_EOL) === false) {
    throw new RuntimeException('Failed to write focused comparison evidence.');
}
fwrite(STDOUT, 'All three targeted scenarios collected in both orders; diagnostic only.' . PHP_EOL);
