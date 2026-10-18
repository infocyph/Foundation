<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('requires tagged Foundation releases to pass quality and production consumers', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/security-standards.yml');
    $jobs = $workflow['jobs'];

    expect($jobs['release']['needs'] ?? [])->toContain('phpforge', 'production-consumer', 'release-evidence', 'runtime-hosts')
        ->and($jobs['release']['if'])->toContain("startsWith(github.ref, 'refs/tags/')")
        ->not->toContain('always(', 'failure(', 'cancelled(');

    foreach (['phpforge', 'production-consumer'] as $job) {
        expect($jobs[$job]['if'] ?? null)->toBeNull();
    }

    expect($jobs['runtime-hosts']['uses'])->toBe('./.github/workflows/foundation4-host-certification.yml')
        ->and($jobs['runtime-hosts']['if'])->toContain("startsWith(github.ref, 'refs/tags/')");
    $hosts = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/foundation4-host-certification.yml');
    expect($hosts['on'])->toHaveKey('workflow_call');
});

it('enforces independent complete performance batches without mixing their artifacts', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/foundation4-host-certification.yml');
    $measure = $workflow['jobs']['paired-host'];
    $compare = $workflow['jobs']['compare-paired-host'];

    expect($measure['strategy']['matrix']['batch'])->toBe(['primary', 'confirmation'])
        ->and($compare['strategy']['matrix']['batch'])->toBe(['primary', 'confirmation'])
        ->and($measure['strategy']['matrix']['host'])->toBe(['fpm', 'runwire'])
        ->and($measure['strategy']['matrix']['workers'])->toBe([1, 4])
        ->and($measure['strategy']['matrix']['concurrency'])->toBe([1, 10, 50])
        ->and((int) $measure['env']['FOUNDATION_HOST_MIN_SECONDS'])->toBeGreaterThanOrEqual(60)
        ->and((int) $measure['env']['FOUNDATION_HOST_WARMUP_SECONDS'])->toBeGreaterThanOrEqual(30);

    $download = array_values(array_filter($compare['steps'], static fn(array $step): bool => isset($step['with']['pattern'])));
    expect($download)->toHaveCount(1)
        ->and($download[0]['with']['pattern'])->toBe('foundation4-pair-${{ matrix.batch }}-*');

    $run = implode("\n", array_column($compare['steps'], 'run'));
    expect($run)->toContain('foundation4-host-merge.php', 'foundation4-module-cli-compare.php')
        ->not->toContain('|| true', 'continue-on-error');
});
