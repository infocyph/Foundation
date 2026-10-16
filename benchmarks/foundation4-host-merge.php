<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Infocyph\Foundation\Benchmarks\Support\Foundation4HostComparison;

if ($argc !== 3) {
    throw new InvalidArgumentException('Usage: php foundation4-host-merge.php ARTIFACT_DIRECTORY OUTPUT');
}
$reports = ['baseline' => [], 'candidate' => []];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1], FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile() || $file->getFilename() !== 'report.json') {
        continue;
    }
    $variant = basename($file->getPath());
    if (!array_key_exists($variant, $reports)) {
        continue;
    }
    $report = json_decode((string) file_get_contents($file->getPathname()), true, flags: JSON_THROW_ON_ERROR);
    $reports[$variant][] = $report;
}
$merged = [];
foreach ($reports as $variant => $parts) {
    if (count($parts) !== 12) {
        throw new RuntimeException('The paired host matrix requires exactly 12 ' . $variant . ' reports.');
    }
    $combined = $parts[0];
    $combined['hardware_id'] = 'paired-per-scenario-runners';
    $combined['hosts'] = [];
    $combined['classification'] = 'same-runner baseline/candidate pairs; hardware identity is enforced separately for every scenario';
    foreach ($parts as $part) {
        foreach (['sha', 'php', 'protocol', 'workload_profile', 'request_count', 'repetitions', 'composer_lock_sha256', 'fixture_id', 'tool_sha'] as $field) {
            if (!isset($part[$field]) || $part[$field] !== $combined[$field]) {
                throw new RuntimeException('Paired host report identity mismatch: ' . $field);
            }
        }
        foreach ($part['hosts'] as $host => $cases) {
            foreach ($cases as $case) {
                $case['hardware_id'] = $part['hardware_id'];
                $combined['hosts'][$host][] = $case;
            }
        }
    }
    $merged[$variant] = $combined;
}
if ($merged['baseline']['tool_sha'] !== $merged['candidate']['tool_sha']
    || $merged['baseline']['fixture_id'] !== $merged['candidate']['fixture_id']
) {
    throw new RuntimeException('Baseline/candidate tooling or workload differs.');
}
$comparison = Foundation4HostComparison::compare($merged['baseline'], $merged['candidate']);
file_put_contents($argv[2], json_encode($comparison, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
foreach ($merged as $variant => $report) {
    file_put_contents(dirname($argv[2]) . '/' . $variant . '-merged.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
}
if (!$comparison['passed']) {
    throw new RuntimeException('Matched host comparison failed; inspect variance and regression evidence.');
}
fwrite(STDOUT, 'All 12 paired host comparisons passed.' . PHP_EOL);
