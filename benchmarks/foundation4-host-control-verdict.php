<?php

declare(strict_types=1);

/**
 * Frozen identical-runtime host calibration (diagnostic only).
 *
 * This never certifies the Foundation source, modifies the 2% / 2.5%
 * acceptance budgets, or substitutes for the full 24-pair matrix and soaks.
 */
const CONTROL_BASELINE_SHA = 'd6ea8a2a33194396d3dfd93089449aab3653ab81';
const CONTROL_CANDIDATE_SHA = '3fcebbfe06165a7363ea9e21ef55831469041fb3';
const CONTROL_TOOL_SHA = 'd1fa5cd97ece83f713e1e709543bc97ec575330d';
const CONTROL_LOCK_SHA256 = '47714e7847c611f6cd8cef4caa45ac9ffae71188c93b7424d9daa867866f4ec7';

if (count($argv) !== 4 || !is_dir($argv[1])) {
    throw new InvalidArgumentException('Usage: php foundation4-host-control-verdict.php ARTIFACT_DIR REPORT.json REPORT.md');
}

/** @throws RuntimeException */
function controlRead(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Missing required control artifact: ' . $path);
    }
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Unreadable control artifact: ' . $path);
    }

    return $content;
}

/** @return array<string,mixed> */
function controlJson(string $path): array
{
    $result = json_decode(controlRead($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($result) || array_is_list($result)) {
        throw new RuntimeException('Expected control evidence object: ' . $path);
    }

    return $result;
}

/** @return array{median:float,cv:float} */
function controlRuns(array $runs): array
{
    if (count($runs) !== 5) {
        throw new RuntimeException('Calibration requires five complete sustained trials per source.');
    }
    $rpms = [];
    foreach ($runs as $run) {
        if (!is_array($run)
            || !is_int($run['successful'] ?? null) || $run['successful'] < 1000
            || ($run['failed'] ?? null) !== 0
            || ($run['authenticated'] ?? null) !== true
            || !is_numeric($run['duration_seconds'] ?? null) || (float) $run['duration_seconds'] < 300.0
            || !is_numeric($run['rpm'] ?? null) || (float) $run['rpm'] <= 0
            || !is_int($run['database_queries'] ?? null) || $run['database_queries'] < $run['successful']
            || !is_int($run['application_cache_hits'] ?? null) || $run['application_cache_hits'] < $run['successful']
        ) {
            throw new RuntimeException('Missing authenticated, sustained, successful HTTP evidence.');
        }
        $rpm = 60.0 * $run['successful'] / (float) $run['duration_seconds'];
        if (abs($rpm - (float) $run['rpm']) > $rpm * .005) {
            throw new RuntimeException('Recorded successful RPM disagrees with request and time counters.');
        }
        $rpms[] = $rpm;
    }
    sort($rpms, SORT_NUMERIC);
    $mean = array_sum($rpms) / 5.0;
    $variance = array_sum(array_map(static fn(float $rpm): float => ($rpm - $mean) ** 2, $rpms)) / 5.0;

    return ['median' => $rpms[2], 'cv' => sqrt($variance) / $mean * 100.0];
}

/** @return array<string,int> */
function controlStat(string $text): array
{
    preg_match_all('/^([a-z_]+)\s+(\d+)$/m', $text, $matches, PREG_SET_ORDER);
    $result = [];
    foreach ($matches as $match) {
        $result[$match[1]] = (int) $match[2];
    }

    return $result;
}

function controlCounter(string $text, string $regex): int
{
    if (preg_match($regex, $text, $match) !== 1) {
        throw new RuntimeException('A required host scheduling diagnostic is missing.');
    }

    return (int) $match[1];
}

function controlTelemetry(string $directory, string $host, array &$totals): void
{
    for ($trial = 1; $trial <= 5; ++$trial) {
        $prefix = $directory . '/' . $host . '-w4-c1-r' . $trial;
        $before = $prefix . '.before';
        $after = $prefix . '.after';
        $cpuBefore = controlStat(controlRead($before . '/cgroup-cpu.stat.txt'));
        $cpuAfter = controlStat(controlRead($after . '/cgroup-cpu.stat.txt'));
        $throttleBefore = $cpuBefore['throttled_usec'] ?? null;
        $throttleAfter = $cpuAfter['throttled_usec'] ?? null;
        if (!is_int($throttleBefore) || !is_int($throttleAfter) || $throttleAfter < $throttleBefore) {
            throw new RuntimeException('Malformed cgroup CPU throttling counters.');
        }
        $totals['cgroup_throttle_usec'] += $throttleAfter - $throttleBefore;
        $stealRegex = '/^cpu\s+(?:\d+\s+){7}(\d+)/m';
        $totals['cpu_steal_ticks'] += controlCounter(controlRead($after . '/cpu-stat.txt'), $stealRegex)
            - controlCounter(controlRead($before . '/cpu-stat.txt'), $stealRegex);
        $pressureRegex = '/^some\s+[^\n]*\btotal=(\d+)/m';
        $totals['cpu_pressure_usec'] += controlCounter(controlRead($after . '/pressure-cpu.txt'), $pressureRegex)
            - controlCounter(controlRead($before . '/pressure-cpu.txt'), $pressureRegex);
        foreach (['redis' => ['rejected_connections' => 'redis_rejections', 'keyspace_misses' => 'redis_misses'],
            'postgres' => ['deadlocks' => 'postgres_deadlocks', 'conflicts' => 'postgres_conflicts'],
        ] as $backend => $fields) {
            $old = controlJson($before . '/backends.json');
            $new = controlJson($after . '/backends.json');
            foreach ($fields as $key => $total) {
                $oldValue = $old[$backend][$key] ?? null;
                $newValue = $new[$backend][$key] ?? null;
                if (!is_int($oldValue) || !is_int($newValue) || $newValue < $oldValue) {
                    throw new RuntimeException('Malformed backend counters for ' . $backend . '.' . $key);
                }
                $totals[$total] += $newValue - $oldValue;
            }
        }
        foreach (['cpu2', 'cpu3'] as $cpu) {
            if (trim(controlRead($before . '/' . $cpu . '-frequency-khz.txt')) === 'unavailable'
                || trim(controlRead($after . '/' . $cpu . '-frequency-khz.txt')) === 'unavailable') {
                ++$totals['unavailable_cpu_frequency_snapshots'];
            }
        }
        $load = controlJson($prefix . '.load.json');
        if (($load['failed'] ?? null) !== 0 || !is_numeric($load['load_cpu_percent'] ?? null)) {
            throw new RuntimeException('Missing or failed load-generator diagnostics.');
        }
        $totals['max_client_cpu_percent'] = max($totals['max_client_cpu_percent'], (float) $load['load_cpu_percent']);
        ++$totals['trials'];
    }
}

$root = rtrim($argv[1], '/');
$cases = [];
$reference = [];
$consistent = true;
$telemetry = [
    'trials' => 0,
    'cpu_steal_ticks' => 0,
    'cgroup_throttle_usec' => 0,
    'cpu_pressure_usec' => 0,
    'redis_rejections' => 0,
    'redis_misses' => 0,
    'postgres_deadlocks' => 0,
    'postgres_conflicts' => 0,
    'unavailable_cpu_frequency_snapshots' => 0,
    'max_client_cpu_percent' => 0.0,
];
foreach (['fpm', 'runwire'] as $host) {
    foreach (['primary', 'confirmation'] as $batch) {
        $pair = $root . '/foundation4-diagnostic-pair-' . $batch . '-' . $host . '-4-1';
        if (trim(controlRead($pair . '/scope.txt')) !== 'diagnostic-only') {
            throw new RuntimeException('A non-diagnostic artifact was included in calibration.');
        }
        $reports = [];
        $summaries = [];
        foreach (['baseline' => CONTROL_BASELINE_SHA, 'candidate' => CONTROL_CANDIDATE_SHA] as $variant => $sha) {
            $report = controlJson($pair . '/' . $variant . '/report.json');
            foreach (['schema' => 3, 'sha' => $sha, 'tool_sha' => CONTROL_TOOL_SHA,
                'composer_lock_sha256' => CONTROL_LOCK_SHA256,
                'request_count' => 1000, 'repetitions' => 5, 'warmup_seconds' => 300,
            ] as $field => $expected) {
                if (($report[$field] ?? null) !== $expected) {
                    throw new RuntimeException('Control evidence identity mismatch: ' . $field);
                }
            }
            $hosts = $report['hosts'] ?? null;
            $scenario = is_array($hosts) && count($hosts) === 1 ? ($hosts[$host] ?? null) : null;
            if (!is_array($scenario) || count($scenario) !== 1
                || !is_array($scenario[0] ?? null)
                || ($scenario[0]['workers'] ?? null) !== 4 || ($scenario[0]['concurrency'] ?? null) !== 1
                || !is_array($scenario[0]['runs'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($scenario[0]['auth_fixture_sha256'] ?? '')) !== 1) {
                throw new RuntimeException('Missing or incorrect host control scenario.');
            }
            $summary = controlRuns($scenario[0]['runs']);
            if (!is_numeric($scenario[0]['median_successful_rpm'] ?? null)
                || abs($summary['median'] - (float) $scenario[0]['median_successful_rpm']) > .02) {
                throw new RuntimeException('Reported control median does not match trial evidence.');
            }
            $reports[$variant] = $report;
            $summaries[$variant] = $summary;
            controlTelemetry($pair . '/' . $variant, $host, $telemetry);
        }
        foreach (['hardware_id', 'php', 'protocol', 'workload_profile', 'fixture_id', 'tool_sha',
            'composer_lock_sha256',
        ] as $field) {
            if (!is_string($reports['baseline'][$field] ?? null)
                || $reports['baseline'][$field] === ''
                || $reports['baseline'][$field] !== $reports['candidate'][$field]) {
                throw new RuntimeException('Control sources did not share identity: ' . $field);
            }
        }
        $authBefore = $reports['baseline']['hosts'][$host][0]['auth_fixture_sha256'];
        $authAfter = $reports['candidate']['hosts'][$host][0]['auth_fixture_sha256'];
        if ($authBefore !== $authAfter) {
            throw new RuntimeException('Authentication inputs did not match between sources.');
        }
        $baseline = $summaries['baseline'];
        $candidate = $summaries['candidate'];
        $delta = 100.0 * ($candidate['median'] / $baseline['median'] - 1.0);
        $cv = max($baseline['cv'], $candidate['cv']);
        // A control needs *absolute* near-zero source drift, not just absence
        // of negative regressions. This cannot alter the release comparator.
        $stablePair = $cv <= 2.5 && abs($delta) <= 2.0;
        $consistent = $consistent && $stablePair;
        $reference[$host][$batch] = $baseline['median'];
        $cases[] = [
            'host' => $host, 'batch' => $batch,
            'hardware_id' => $reports['baseline']['hardware_id'],
            'baseline_rpm' => round($baseline['median'], 2),
            'candidate_rpm' => round($candidate['median'], 2),
            'delta_percent' => round($delta, 3),
            'baseline_cv_percent' => round($baseline['cv'], 3),
            'candidate_cv_percent' => round($candidate['cv'], 3),
            'status' => $stablePair ? 'consistent' : 'unstable_identical_runtime',
        ];
    }
}
$crossVm = [];
foreach (['fpm', 'runwire'] as $host) {
    $drift = 100.0 * ($reference[$host]['confirmation'] / $reference[$host]['primary'] - 1.0);
    $crossVm[$host] = round($drift, 3);
    $consistent = $consistent && abs($drift) <= 2.0;
}
$result = [
    'scope' => 'identical-runtime non-certifying calibration',
    'calibration_stable' => $consistent,
    'release_certified' => false,
    'baseline_sha' => CONTROL_BASELINE_SHA,
    'candidate_sha' => CONTROL_CANDIDATE_SHA,
    'tool_sha' => CONTROL_TOOL_SHA,
    'composer_lock_sha256' => CONTROL_LOCK_SHA256,
    'control_absolute_drift_limit_percent' => 2.0,
    'control_cv_limit_percent' => 2.5,
    'cases' => $cases,
    'cross_vm_reference_drift_percent' => $crossVm,
    'telemetry' => $telemetry,
];
if (file_put_contents($argv[2], json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL) === false) {
    throw new RuntimeException('Unable to write calibration JSON.');
}
$md = [
    '# Foundation 4 frozen w4/c1 calibration',
    '',
    '**Verdict: ' . ($consistent ? 'CONTROLS CONSISTENT' : 'CALIBRATION FAILED — SOURCE REGRESSION NOT PROVEN') . '**',
    '',
    'Non-certifying diagnostic only. The full source-only matrices and soaks remain required.',
    '',
    '| Host | Batch | Baseline RPM | Candidate RPM | Delta | Maximum CV | Verdict |',
    '|---|---|---:|---:|---:|---:|---|',
];
foreach ($cases as $case) {
    $md[] = sprintf('| %s | %s | %.2f | %.2f | %+.3f%% | %.3f%% | %s |',
        $case['host'], $case['batch'], $case['baseline_rpm'], $case['candidate_rpm'],
        $case['delta_percent'], max($case['baseline_cv_percent'], $case['candidate_cv_percent']), $case['status']);
}
$md[] = '';
$md[] = sprintf('Cross-VM identical-baseline drift: FPM %+.3f%%, Runwire %+.3f%%.', $crossVm['fpm'], $crossVm['runwire']);
$md[] = sprintf('Evidence: %d sustained trials, CPU pressure %.1f seconds, CPU steal ticks %d, cgroup throttle %d µs, Redis rejected/misses %d/%d, PostgreSQL deadlocks/conflicts %d/%d, missing CPU-frequency snapshots %d, maximum load-generator CPU %.3f%%.',
    $telemetry['trials'], $telemetry['cpu_pressure_usec'] / 1000000.0, $telemetry['cpu_steal_ticks'],
    $telemetry['cgroup_throttle_usec'], $telemetry['redis_rejections'], $telemetry['redis_misses'],
    $telemetry['postgres_deadlocks'], $telemetry['postgres_conflicts'], $telemetry['unavailable_cpu_frequency_snapshots'],
    $telemetry['max_client_cpu_percent']);
$md[] = '';
$md[] = 'The 2% regression and 2.5% variance *release* limits are unchanged. Identical-runtime control failure blocks source-attributed conclusions; it is not a Foundation runtime regression.';
$md[] = '';
if (file_put_contents($argv[3], implode(PHP_EOL, $md)) === false) {
    throw new RuntimeException('Unable to write calibration Markdown.');
}
fwrite(STDOUT, implode(PHP_EOL, $md));
