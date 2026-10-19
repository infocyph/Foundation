<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Support\FoundationModuleCliComparison;

/** @return array<string,mixed> */
function b7ModuleCliReport(float $duration = 5.0): array
{
    $commands = [];
    foreach (['list', 'show', 'doctor', 'plan', 'install_dry_run', 'remove_dry_run', 'enable'] as $name) {
        $commands[$name] = [
            'cold_ms' => $duration * 2, 'warm_p50_ms' => $duration,
            'warm_p95_ms' => $duration * 1.5, 'warm_p99_ms' => $duration * 2,
            'warm_samples' => 15,
        ];
    }

    return [
        'schema_version' => 2, 'module_count' => 3, 'feature_count' => 8,
        'source_sha' => str_repeat('a', 40), 'source_composer_sha256' => str_repeat('b', 64),
        'php' => '8.5', 'php_sapi' => 'cli', 'opcache_cli' => false,
        'fixture' => 'isolated Composer fixture', 'commands' => $commands,
    ];
}

it('reports v2 cold/warm CLI deltas without treating a microbenchmark as matched-host RPM', function (): void {
    $candidate = b7ModuleCliReport(5.5);
    $candidate['source_sha'] = str_repeat('c', 40);
    $result = FoundationModuleCliComparison::compare(b7ModuleCliReport(), $candidate);

    expect($result['host_rpm_certified'])->toBeFalse()
        ->and($result['commands'])->toHaveCount(7)
        ->and($result['commands'][0]['metrics']['warm_p50_ms']['delta_percent'])->toBe(10.0);
});

it('rejects identical sources, short sample series and incomparable PHP runtimes', function (): void {
    $base = b7ModuleCliReport();
    expect(fn() => FoundationModuleCliComparison::compare($base, $base))
        ->toThrow(InvalidArgumentException::class, 'Different immutable source');

    $candidate = b7ModuleCliReport();
    $candidate['source_sha'] = str_repeat('c', 40);
    $candidate['commands']['plan']['warm_samples'] = 3;
    expect(fn() => FoundationModuleCliComparison::compare($base, $candidate))
        ->toThrow(InvalidArgumentException::class, 'repeated warm CLI samples');

    $candidate = b7ModuleCliReport();
    $candidate['source_sha'] = str_repeat('c', 40);
    $candidate['php'] = '8.4';
    expect(fn() => FoundationModuleCliComparison::compare($base, $candidate))
        ->toThrow(InvalidArgumentException::class, 'php differs');
});

it('rejects missing commands and nonpositive CLI timings', function (): void {
    $base = b7ModuleCliReport();
    $candidate = b7ModuleCliReport();
    $candidate['source_sha'] = str_repeat('c', 40);
    unset($candidate['commands']['remove_dry_run']);
    expect(fn() => FoundationModuleCliComparison::compare($base, $candidate))
        ->toThrow(InvalidArgumentException::class, 'incomplete');

    $candidate = b7ModuleCliReport();
    $candidate['source_sha'] = str_repeat('c', 40);
    $candidate['commands']['show']['warm_p50_ms'] = 0.0;
    expect(fn() => FoundationModuleCliComparison::compare($base, $candidate))
        ->toThrow(InvalidArgumentException::class, 'Invalid CLI timing');
});

it('discloses the Core Runwire inventory change without certifying equivalent CLI or host throughput', function (): void {
    $baseline = b7ModuleCliReport();
    $baseline['feature_count'] = 9;
    $candidate = b7ModuleCliReport();
    $candidate['source_sha'] = str_repeat('c', 40);
    $result = FoundationModuleCliComparison::compare($baseline, $candidate);

    expect($result['catalog_changed'])->toBeTrue()
        ->and($result['baseline_feature_count'])->toBe(9)
        ->and($result['candidate_feature_count'])->toBe(8)
        ->and($result['classification'])->toContain('catalog changed')
        ->and($result['host_rpm_certified'])->toBeFalse();

    $candidate['feature_count'] = 0;
    expect(fn() => FoundationModuleCliComparison::compare($baseline, $candidate))
        ->toThrow(InvalidArgumentException::class, 'incomplete');
});
