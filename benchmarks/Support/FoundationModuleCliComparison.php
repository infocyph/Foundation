<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

/** Cold/warm CLI diagnostic, never a substitute for matched-host RPM. */
final class FoundationModuleCliComparison
{
    /** @var list<string> */
    private const array COMMANDS = ['list', 'show', 'doctor', 'plan', 'install_dry_run', 'remove_dry_run', 'enable'];

    /**
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    public static function compare(array $baseline, array $candidate): array
    {
        foreach ([$baseline, $candidate] as $report) {
            self::validate($report);
        }
        foreach (['schema_version', 'php', 'php_sapi', 'opcache_cli', 'fixture', 'module_count'] as $field) {
            if ($baseline[$field] !== $candidate[$field]) {
                throw new \InvalidArgumentException(sprintf('Incomparable module CLI evidence: %s differs.', $field));
            }
        }
        if ($baseline['source_sha'] === $candidate['source_sha']) {
            throw new \InvalidArgumentException('Different immutable source commits are required.');
        }

        $commands = [];
        foreach (self::COMMANDS as $name) {
            $before = $baseline['commands'][$name];
            $after = $candidate['commands'][$name];
            $metrics = [];
            foreach (['cold_ms', 'warm_p50_ms', 'warm_p95_ms'] as $field) {
                $metrics[$field] = [
                    'baseline_ms' => $before[$field],
                    'candidate_ms' => $after[$field],
                    'delta_percent' => round(($after[$field] / $before[$field] - 1.0) * 100, 3),
                ];
            }
            $commands[] = ['command' => $name, 'metrics' => $metrics];
        }

        return [
            'classification' => $baseline['feature_count'] === $candidate['feature_count']
                ? 'diagnostic CLI microbenchmark comparison, NOT production host RPM certification'
                : 'diagnostic CLI comparison: catalog changed; timing deltas cover different inventories, NOT production host RPM certification',
            'baseline_feature_count' => $baseline['feature_count'],
            'candidate_feature_count' => $candidate['feature_count'],
            'catalog_changed' => $baseline['feature_count'] !== $candidate['feature_count'],
            'baseline_sha' => $baseline['source_sha'],
            'candidate_sha' => $candidate['source_sha'],
            'commands' => $commands,
            'host_rpm_certified' => false,
        ];
    }

    /** @param array<string,mixed> $report */
    private static function validate(array $report): void
    {
        if (($report['schema_version'] ?? null) !== 2
            || ($report['module_count'] ?? null) !== 3
            || !is_int($report['feature_count'] ?? null)
            || $report['feature_count'] < 1
            || !is_string($report['php'] ?? null)
            || ($report['php'] ?? '') === ''
            || !is_string($report['php_sapi'] ?? null)
            || !is_bool($report['opcache_cli'] ?? null)
            || !is_string($report['fixture'] ?? null)
            || !is_string($report['source_sha'] ?? null)
            || preg_match('/^[a-f0-9]{40}$/D', $report['source_sha']) !== 1
            || !is_string($report['source_composer_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $report['source_composer_sha256']) !== 1
            || !is_array($report['commands'] ?? null)
            || array_keys($report['commands']) !== self::COMMANDS
        ) {
            throw new \InvalidArgumentException('Module CLI benchmark evidence is incomplete.');
        }

        foreach ($report['commands'] as $metrics) {
            if (!is_array($metrics) || !is_int($metrics['warm_samples'] ?? null) || $metrics['warm_samples'] < 5) {
                throw new \InvalidArgumentException('Missing repeated warm CLI samples.');
            }
            foreach (['cold_ms', 'warm_p50_ms', 'warm_p95_ms', 'warm_p99_ms'] as $metric) {
                $value = $metrics[$metric] ?? null;
                if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value) || $value <= 0) {
                    throw new \InvalidArgumentException('Invalid CLI timing evidence.');
                }
            }
        }
    }
}
