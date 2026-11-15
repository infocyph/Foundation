<?php

declare(strict_types=1);

/**
 * Accept calibrated source-identical controls only. This never certifies the
 * Foundation source; successful full matrices and soaks are still required.
 */
if (count($argv) !== 2 || !is_file($argv[1])) {
    throw new InvalidArgumentException('Usage: php foundation4-calibration-accept.php REPORT.json');
}

$report = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$toolSha = getenv('FOUNDATION_CONTROL_TOOL_SHA');
if (!is_array($report)
    || array_is_list($report)
    || !is_string($toolSha)
    || preg_match('/\A[a-f0-9]{40}\z/D', $toolSha) !== 1
    || ($report['scope'] ?? null) !== 'identical-runtime non-certifying calibration'
    || ($report['calibration_stable'] ?? null) !== true
    || ($report['release_certified'] ?? null) !== false
    || ($report['baseline_sha'] ?? null) !== 'd6ea8a2a33194396d3dfd93089449aab3653ab81'
    || ($report['candidate_sha'] ?? null) !== '3fcebbfe06165a7363ea9e21ef55831469041fb3'
    || ($report['tool_sha'] ?? null) !== $toolSha
    || ($report['composer_lock_sha256'] ?? null) !== '47714e7847c611f6cd8cef4caa45ac9ffae71188c93b7424d9daa867866f4ec7'
    || ($report['telemetry']['trials'] ?? null) !== 40
    || !is_array($report['cases'] ?? null)
    || count($report['cases']) !== 4
    || !is_array($report['cross_vm_reference_drift_percent'] ?? null)
    || count($report['cross_vm_reference_drift_percent']) !== 2
) {
    throw new RuntimeException('Calibration identity, completeness or stable-verdict check failed.');
}

foreach (['control_absolute_drift_limit_percent' => 2.0, 'control_cv_limit_percent' => 2.5] as $field => $budget) {
    $value = $report[$field] ?? null;
    if ((!is_int($value) && !is_float($value)) || (float) $value !== $budget) {
        throw new RuntimeException('Calibration has an altered numeric limit: ' . $field);
    }
}

$expectedCases = ['fpm:primary' => true, 'fpm:confirmation' => true, 'runwire:primary' => true, 'runwire:confirmation' => true];
$hardwareId = null;
foreach ($report['cases'] as $case) {
    if (!is_array($case)) {
        throw new RuntimeException('Missing authenticated control case.');
    }
    $key = ($case['host'] ?? '') . ':' . ($case['batch'] ?? '');
    $id = $case['hardware_id'] ?? null;
    if (!isset($expectedCases[$key]) || !is_string($id) || $id === ''
        || ($case['status'] ?? null) !== 'consistent'
    ) {
        throw new RuntimeException('Duplicate, missing or unstable control case: ' . $key);
    }
    unset($expectedCases[$key]);
    if ($hardwareId !== null && $hardwareId !== $id) {
        throw new RuntimeException('Controls did not share one physical host identity.');
    }
    $hardwareId = $id;
    foreach (['baseline_rpm', 'candidate_rpm', 'delta_percent', 'baseline_cv_percent', 'candidate_cv_percent'] as $field) {
        $v = $case[$field] ?? null;
        if (!is_int($v) && !is_float($v)) {
            throw new RuntimeException('Missing numeric control evidence: ' . $field);
        }
    }
    if ($case['baseline_rpm'] <= 0 || $case['candidate_rpm'] <= 0
        || abs($case['delta_percent']) > 2.0
        || $case['baseline_cv_percent'] > 2.5
        || $case['candidate_cv_percent'] > 2.5
        || $case['baseline_cv_percent'] < 0
        || $case['candidate_cv_percent'] < 0) {
        throw new RuntimeException('Control exceeds unchanged drift or variance limits: ' . $key);
    }
}
if ($expectedCases !== []) {
    throw new RuntimeException('Incomplete FPM/Runwire primary/reversed controls.');
}
foreach (['fpm', 'runwire'] as $host) {
    $drift = $report['cross_vm_reference_drift_percent'][$host] ?? null;
    if ((!is_int($drift) && !is_float($drift)) || abs((float) $drift) > 2.0) {
        throw new RuntimeException('Unstable order-reversed reference: ' . $host);
    }
}
fwrite(STDOUT, 'All four calibrated, source-identical same-VM controls accepted; full release certification still required.' . PHP_EOL);
