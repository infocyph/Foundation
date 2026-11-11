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
     * @throws \InvalidArgumentException
     */
    public static function compare(array $baseline, array $candidate): array
    {
        self::validate($baseline);
        self::validate($candidate);
        $beforeCommands = $baseline['commands'];
        $afterCommands = $candidate['commands'];
        if (!is_array($beforeCommands) || !is_array($afterCommands)) {
            throw new \InvalidArgumentException('Module CLI evidence requires command maps.');
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
            $before = $beforeCommands[$name] ?? null;
            $after = $afterCommands[$name] ?? null;
            if (!is_array($before) || !is_array($after)) {
                throw new \InvalidArgumentException('Module CLI comparison requires structured command metrics.');
            }
            $metrics = self::metrics($before, $after);
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

    /**
     * @param array<array-key,mixed> $before
     * @param array<array-key,mixed> $after
     * @return array<string,array{baseline_ms:int|float,candidate_ms:int|float,delta_percent:float}>
     * @throws \InvalidArgumentException
     */
    private static function metrics(array $before, array $after): array
    {
        $metrics = [];
        foreach (['cold_ms', 'warm_p50_ms', 'warm_p95_ms'] as $field) {
            $oldMs = $before[$field] ?? null;
            $newMs = $after[$field] ?? null;
            if ((!is_float($oldMs) && !is_int($oldMs))
                || (!is_float($newMs) && !is_int($newMs))
                || $oldMs <= 0.0 || $newMs <= 0.0) {
                throw new \InvalidArgumentException('Module CLI timing values must be positive numbers.');
            }
            $metrics[$field] = [
                'baseline_ms' => $oldMs,
                'candidate_ms' => $newMs,
                'delta_percent' => round(((float) $newMs / (float) $oldMs - 1.0) * 100.0, 3),
            ];
        }

        return $metrics;
    }

    /**
     * @param array<string,mixed> $report
     * @throws \InvalidArgumentException
     */
    private static function validate(array $report): void
    {
        if (($report['schema_version'] ?? null) !== 2
            || ($report['module_count'] ?? null) !== 3
            || !is_int($report['feature_count'] ?? null)
            || $report['feature_count'] < 1
            || !is_string($report['php'] ?? null)
            || $report['php'] === ''
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

        self::validateTimings($report['commands']);
    }

    /**
     * @param array<array-key,mixed> $commands
     * @throws \InvalidArgumentException
     */
    private static function validateTimings(array $commands): void
    {
        foreach ($commands as $metrics) {
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
