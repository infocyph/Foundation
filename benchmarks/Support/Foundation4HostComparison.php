<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use InvalidArgumentException;

/**
 * Fail-closed comparison of matched Foundation 4 native-host throughput evidence.
 *
 * This checks comparable evidence and the 2% regression budget. Release sign-off
 * additionally requires representative workload coverage and host soak evidence.
 */
final class Foundation4HostComparison
{
    private const float MAX_VARIATION_PERCENT = 2.5;

    private const int MIN_REPETITIONS = 5;

    private const float MIN_SUSTAINED_SECONDS = 60.0;

    private const int MIN_WARMUP_SECONDS = 30;

    private const float REGRESSION_BUDGET_PERCENT = 2.0;

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    public static function compare(array $baseline, array $candidate): array
    {
        foreach ([$baseline, $candidate] as $report) {
            if (($report['schema'] ?? null) !== 2
                || !is_string($report['sha'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/D', $report['sha']) !== 1
                || !is_string($report['php'] ?? null)
                || $report['php'] === ''
                || !is_int($report['request_count'] ?? null)
                || $report['request_count'] < 100
                || !is_int($report['repetitions'] ?? null)
                || !is_string($report['hardware_id'] ?? null)
                || $report['hardware_id'] === ''
                || !is_string($report['composer_lock_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $report['composer_lock_sha256']) !== 1
                || ($report['protocol'] ?? null) !== 'HTTP/1.1 with per-response status/body checks'
                || !is_string($report['workload_profile'] ?? null)
                || $report['workload_profile'] === ''
                || !is_array($report['hosts'] ?? null)
                || ($report['repetitions'] ?? 0) < self::MIN_REPETITIONS
                || !is_int($report['warmup_seconds'] ?? null)
                || $report['warmup_seconds'] < self::MIN_WARMUP_SECONDS
            ) {
                throw new InvalidArgumentException('Host benchmark evidence is incomplete or non-certifiable.');
            }
        }

        foreach (['hardware_id', 'php', 'protocol', 'workload_profile', 'request_count', 'repetitions', 'composer_lock_sha256'] as $field) {
            if ($baseline[$field] !== $candidate[$field]) {
                throw new InvalidArgumentException(sprintf('Incomparable host evidence: %s differs.', $field));
            }
        }

        if (($baseline['warmup_seconds'] ?? 0) !== ($candidate['warmup_seconds'] ?? 0)) {
            throw new InvalidArgumentException('Incomparable host evidence: warmup_seconds differs.');
        }

        if ($baseline['sha'] === $candidate['sha']) {
            throw new InvalidArgumentException('Matched host comparison requires distinct immutable baseline/candidate commits.');
        }

        $baselineCases = self::cases($baseline);
        $candidateCases = self::cases($candidate);
        if (array_keys($baselineCases) !== array_keys($candidateCases)) {
            throw new InvalidArgumentException('The host benchmark scenario matrices are different.');
        }

        $results = [];
        $passed = true;
        foreach ($baselineCases as $key => $before) {
            $after = $candidateCases[$key];
            if ($before['hardware_id'] !== $after['hardware_id']) {
                throw new InvalidArgumentException('Baseline/candidate scenario hardware differs: ' . $key);
            }
            $baselineMedian = self::median($before['rpms']);
            $candidateMedian = self::median($after['rpms']);
            $deltaPercent = (($candidateMedian / $baselineMedian) - 1.0) * 100.0;
            $variation = max(self::coefficientOfVariation($before['rpms']), self::coefficientOfVariation($after['rpms']));
            $stable = $variation <= self::MAX_VARIATION_PERCENT;
            $withinBudget = $deltaPercent >= -self::REGRESSION_BUDGET_PERCENT - 1e-10;
            $casePassed = $stable && $withinBudget;
            $passed = $passed && $casePassed;
            $results[] = [
                'scenario' => $key,
                'hardware_id' => $before['hardware_id'],
                'baseline_median_successful_rpm' => round($baselineMedian, 2),
                'candidate_median_successful_rpm' => round($candidateMedian, 2),
                'delta_percent' => round($deltaPercent, 3),
                'max_cv_percent' => round($variation, 3),
                'status' => !$stable ? 'inconclusive_variance' : ($withinBudget ? 'pass' : 'regression'),
            ];
        }

        return [
            'passed' => $passed,
            'regression_budget_percent' => self::REGRESSION_BUDGET_PERCENT,
            'max_cv_percent' => self::MAX_VARIATION_PERCENT,
            'min_repetition_seconds' => self::MIN_SUSTAINED_SECONDS,
            'hardware_id' => $baseline['hardware_id'],
            'workload_profile' => $baseline['workload_profile'],
            'baseline_sha' => $baseline['sha'] ?? null,
            'candidate_sha' => $candidate['sha'] ?? null,
            'cases' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, array{hardware_id:string,rpms:list<float>}>
     */
    private static function cases(array $report): array
    {
        $result = [];
        foreach (['fpm', 'runwire'] as $host) {
            $entries = $report['hosts'][$host] ?? null;
            if (!is_array($entries)) {
                throw new InvalidArgumentException(sprintf('Missing native host: %s.', $host));
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    throw new InvalidArgumentException('Malformed native host scenario.');
                }

                $workers = $entry['workers'] ?? null;
                $concurrency = $entry['concurrency'] ?? null;
                $runs = $entry['runs'] ?? null;
                if (!in_array($workers, [1, 4], true)
                    || !in_array($concurrency, [1, 10, 50], true)
                    || !is_array($runs)
                    || count($runs) !== $report['repetitions']
                ) {
                    throw new InvalidArgumentException('Missing or unrecognized native host scenario.');
                }

                $key = sprintf('%s-w%d-c%d', $host, $workers, $concurrency);
                if (isset($result[$key])) {
                    throw new InvalidArgumentException('Duplicate native host benchmark scenario.');
                }

                $values = [];
                foreach ($runs as $run) {
                    if (!is_array($run)
                        || !is_int($run['requests'] ?? null)
                        || $run['requests'] < $report['request_count']
                        || ($run['successful'] ?? null) !== $run['requests']
                        || ($run['failed'] ?? null) !== 0
                        || !self::positiveNumber($run['rpm'] ?? null)
                        || !self::positiveNumber($run['duration_seconds'] ?? null)
                        || $run['duration_seconds'] < self::MIN_SUSTAINED_SECONDS
                        || !self::positiveNumber($run['peak_rss_mb'] ?? null)
                        || ($run['samples'] ?? 0) < 10
                    ) {
                        throw new InvalidArgumentException(sprintf('Invalid or unsustained evidence for %s.', $key));
                    }

                    if ($report['workload_profile'] === 'authenticated-read'
                        && (($run['authenticated'] ?? false) !== true
                            || ($run['database_queries'] ?? 0) < 2 * $run['successful']
                            || ($run['application_cache_hits'] ?? 0) !== $run['successful'])
                    ) {
                        throw new InvalidArgumentException('Authenticated workload query/cache evidence is incomplete.');
                    }

                    $actualRpm = (float) $run['successful'] * 60.0 / (float) $run['duration_seconds'];
                    if (abs($actualRpm - (float) $run['rpm']) / $actualRpm > 0.005) {
                        throw new InvalidArgumentException(sprintf('Inconsistent successful RPM for %s.', $key));
                    }

                    $values[] = (float) $run['rpm'];
                }

                $hardware = $entry['hardware_id'] ?? $report['hardware_id'];
                if (!is_string($hardware) || $hardware === '') {
                    throw new InvalidArgumentException('Scenario hardware identity is missing.');
                }
                $result[$key] = ['hardware_id' => $hardware, 'rpms' => $values];
            }
        }

        if (count($result) !== 12) {
            throw new InvalidArgumentException('Complete 1/4-worker, 1/10/50-concurrency coverage is required.');
        }

        ksort($result);

        return $result;
    }

    /** @param list<float> $values */
    private static function coefficientOfVariation(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return sqrt($variance / count($values)) / $mean * 100.0;
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2.0
            : $values[$mid];
    }

    private static function positiveNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0;
    }
}
