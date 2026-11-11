<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Support\Foundation4HostComparison;

/**
 * @return array{
 *     schema:int,
 *     sha:string,
 *     hardware_id:string,
 *     php:string,
 *     protocol:string,
 *     workload_profile:string,
 *     composer_lock_sha256:string,
 *     request_count:int,
 *     repetitions:int,
 *     warmup_seconds:int,
 *     hosts:array<string,list<array{
 *         workers:int,
 *         concurrency:int,
 *         runs:list<array{
 *             requests:int,
 *             successful:int,
 *             failed:int,
 *             duration_seconds:float,
 *             rpm:float,
 *             peak_rss_mb:float,
 *             samples:int
 *         }>
 *     }>>
 * }
 */
function foundationHostFixture(float $rpm = 1000.0, bool $candidate = false): array
{
    $hosts = [];
    foreach (['fpm', 'runwire'] as $host) {
        foreach ([1, 4] as $workers) {
            foreach ([1, 10, 50] as $concurrency) {
                $hosts[$host][] = [
                    'workers' => $workers,
                    'concurrency' => $concurrency,
                    'runs' => array_fill(0, 5, [
                        'requests' => 2000,
                        'successful' => 2000,
                        'failed' => 0,
                        'duration_seconds' => 120_000.0 / $rpm,
                        'rpm' => $rpm,
                        'peak_rss_mb' => 100.0,
                        'samples' => 30,
                    ]),
                ];
            }
        }
    }

    return [
        'schema' => 2,
        'sha' => str_repeat($candidate ? 'c' : 'a', 40),
        'hardware_id' => 'same-dedicated-machine',
        'php' => '8.4.26',
        'protocol' => 'HTTP/1.1 with per-response status/body checks',
        'workload_profile' => 'auth-db-cache-v1',
        'composer_lock_sha256' => str_repeat('b', 64),
        'request_count' => 1000,
        'repetitions' => 5,
        'warmup_seconds' => 30,
        'hosts' => $hosts,
    ];
}

it('accepts a stable throughput change inside the strict 2 percent budget', function (): void {
    $base = foundationHostFixture();
    $candidate = foundationHostFixture(980.0);
    $candidate['sha'] = str_repeat('c', 40);

    $result = Foundation4HostComparison::compare($base, $candidate);

    $firstCase = $result['cases'][0] ?? null;
    if (!is_array($firstCase)) {
        throw new \UnexpectedValueException('Host comparison must contain a measured case.');
    }
    expect($result['passed'])->toBeTrue()
        ->and($result['cases'])->toHaveCount(12)
        ->and($firstCase['status'])->toBe('pass')
        ->and($firstCase['delta_percent'])->toBe(-2.0);
});

it('rejects a throughput regression exceeding 2 percent', function (): void {
    $result = Foundation4HostComparison::compare(foundationHostFixture(), foundationHostFixture(979.0, true));

    if (!isset($result['cases'][0])) {
        throw new \UnexpectedValueException('Expected a structured host regression case.');
    }
    expect($result['passed'])->toBeFalse()
        ->and($result['cases'][0]['status'])->toBe('regression');
});

it('rejects noisy evidence instead of silently waiving the throughput budget', function (): void {
    $candidate = foundationHostFixture(candidate: true);
    $candidate['hosts']['fpm'][0]['runs'][0]['rpm'] = 500.0;
    $candidate['hosts']['fpm'][0]['runs'][0]['duration_seconds'] = 240.0;

    $result = Foundation4HostComparison::compare(foundationHostFixture(), $candidate);

    \PHPUnit\Framework\Assert::assertIsArray(($result['cases'][0] ?? null));

    expect($result['passed'])->toBeFalse()
        ->and($result['cases'][0]['status'])->toBe('inconclusive_variance')
        ->and($result['cases'][0]['baseline_cv_percent'])->toBe(0.0)
        ->and($result['cases'][0]['candidate_cv_percent'])->toBeGreaterThan(2.5)
        ->and($result['cases'][0]['max_cv_percent'])->toBeGreaterThan(2.5);
});

it('attributes baseline-only measurement noise without reclassifying it as a passing RPM gain', function (): void {
    $baseline = foundationHostFixture(900.0);
    $baseline['hosts']['runwire'][5]['runs'][0]['rpm'] = 500.0;
    $baseline['hosts']['runwire'][5]['runs'][0]['duration_seconds'] = 240.0;
    $candidate = foundationHostFixture(1000.0, true);

    $result = Foundation4HostComparison::compare($baseline, $candidate);
    $noisy = array_find($result['cases'], static fn(array $case): bool => $case['scenario'] === 'runwire-w4-c50');

    \PHPUnit\Framework\Assert::assertIsArray($noisy);
    expect($result['passed'])->toBeFalse()

        ->and($noisy['status'])->toBe('inconclusive_variance')
        ->and($noisy['baseline_cv_percent'])->toBeGreaterThan(2.5)
        ->and($noisy['candidate_cv_percent'])->toBe(0.0)
        ->and($noisy['delta_percent'])->toBeGreaterThan(0.0);
});

it('rejects mismatched host identities and runtime dependencies', function (): void {
    $candidate = foundationHostFixture(candidate: true);
    $candidate['hardware_id'] = 'another-machine';

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'hardware_id differs.');

    $candidate = foundationHostFixture(candidate: true);
    $candidate['warmup_seconds'] = 31;
    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'warmup_seconds differs.');

    $candidate = foundationHostFixture(candidate: true);
    $candidate['composer_lock_sha256'] = str_repeat('d', 64);

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'composer_lock_sha256 differs.');
});

it('rejects short runs, invalid responses and missing benchmark scenarios', function (): void {
    $candidate = foundationHostFixture(candidate: true);
    $candidate['hosts']['runwire'][5]['runs'][0]['duration_seconds'] = 0.2;

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'Invalid or unsustained evidence');

    $candidate = foundationHostFixture(candidate: true);
    $candidate['hosts']['runwire'][5]['runs'][0]['successful'] = 999;

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'Invalid or unsustained evidence');

    $candidate = foundationHostFixture(candidate: true);
    array_pop($candidate['hosts']['runwire']);

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'Complete 1/4-worker');
});

it('requires two sustained minutes in every baseline and candidate measured trial', function (bool $candidateRun, float $seconds): void {
    $base = foundationHostFixture();
    $candidate = foundationHostFixture(candidate: true);
    if ($candidateRun) {
        $run = $candidate['hosts']['fpm'][0]['runs'][0];
        $run['duration_seconds'] = $seconds;
        $run['rpm'] = (float) $run['successful'] * 60.0 / $seconds;
        $candidate['hosts']['fpm'][0]['runs'][0] = $run;
    } else {
        $run = $base['hosts']['fpm'][0]['runs'][0];
        $run['duration_seconds'] = $seconds;
        $run['rpm'] = (float) $run['successful'] * 60.0 / $seconds;
        $base['hosts']['fpm'][0]['runs'][0] = $run;
    }

    expect(fn() => Foundation4HostComparison::compare($base, $candidate))
        ->toThrow(InvalidArgumentException::class, 'Invalid or unsustained evidence');
})->with([false, true])->with([59.999, 60.0, 119.999]);

it('requires the approved warm-up in both reports before comparing throughput', function (?int $seconds): void {
    $base = foundationHostFixture();
    $candidate = foundationHostFixture(candidate: true);
    $base['warmup_seconds'] = $seconds;
    $candidate['warmup_seconds'] = $seconds;

    expect(fn() => Foundation4HostComparison::compare($base, $candidate))
        ->toThrow(InvalidArgumentException::class, 'incomplete or non-certifiable');
})->with([null, 0, 29]);

it('refuses to treat short shared-runner smoke evidence as a certified comparison', function (): void {
    $candidate = foundationHostFixture(candidate: true);
    $candidate['hardware_id'] = null;

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'incomplete or non-certifiable');

    $candidate = foundationHostFixture(candidate: true);
    $candidate['repetitions'] = 3;

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'incomplete or non-certifiable');
});

it('rejects identical immutable baseline and candidate sources even at identical RPM', function (): void {
    $source = foundationHostFixture();

    expect(fn(): array => Foundation4HostComparison::compare($source, $source))
        ->toThrow(InvalidArgumentException::class, 'distinct immutable baseline/candidate commits');
});

it('requires matching physical runner identity for every paired scenario', function (): void {
    $base = foundationHostFixture();
    $candidate = foundationHostFixture(candidate: true);
    $base['hosts']['fpm'][0]['hardware_id'] = 'runner-one';
    $candidate['hosts']['fpm'][0]['hardware_id'] = 'runner-two';

    expect(fn() => Foundation4HostComparison::compare($base, $candidate))
        ->toThrow(InvalidArgumentException::class, 'scenario hardware differs');
});

it('requires authenticated query and cache evidence from every successful response', function (): void {
    $base = foundationHostFixture();
    $candidate = foundationHostFixture(candidate: true);
    foreach ([&$base, &$candidate] as &$report) {
        $report['workload_profile'] = 'authenticated-read';
        foreach ($report['hosts'] as &$cases) {
            foreach ($cases as &$case) {
                foreach ($case['runs'] as &$run) {
                    $run += ['authenticated' => true, 'database_queries' => 4000, 'application_cache_hits' => 2000];
                }
                unset($run);
            }
            unset($case);
        }
        unset($cases);
    }
    unset($report);
    expect(Foundation4HostComparison::compare($base, $candidate)['passed'])->toBeTrue();
    $candidate['hosts']['runwire'][0]['runs'][0]['application_cache_hits'] = 1999;
    expect(fn() => Foundation4HostComparison::compare($base, $candidate))
        ->toThrow(InvalidArgumentException::class, 'query/cache evidence is incomplete');
});

it('retains both required host soaks when aggregating separately paired scenario artifacts', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-host-merge-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);

    try {
        foreach (['baseline', 'candidate'] as $variant) {
            $report = foundationHostFixture(candidate: $variant === 'candidate');
            $report['fixture_id'] = 'test-fixture';
            $report['tool_sha'] = str_repeat('d', 40);
            foreach ($report['hosts'] as $host => $cases) {
                foreach ($cases as $case) {
                    $part = $report;
                    $part['hosts'] = [$host => [$case]];
                    $part['soak_window_seconds'] = 0;
                    if ($case['workers'] === 4 && $case['concurrency'] === 10) {
                        $part['soak_window_seconds'] = 120;
                        $part['soak'] = [$host => ['workers' => 4, 'concurrency' => 10, 'windows' => array_fill(0, 2, [
                            'duration_seconds' => 120.0, 'successful' => 2000, 'failed' => 0,
                            'samples' => 600, 'peak_rss_mb' => 100.0,
                        ])]];
                    }
                    $path = sprintf('%s/%s-%d-%d/%s', $directory, $host, $case['workers'], $case['concurrency'], $variant);
                    mkdir($path, 0700, true);
                    file_put_contents($path . '/report.json', json_encode($part, JSON_THROW_ON_ERROR));
                }
            }
        }
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/benchmarks/foundation4-host-merge.php', $directory, $directory . '/comparison.json'];
        $runner = new \Infocyph\Foundation\Process\ProcessRunner();
        $result = $runner->run($command, new \Infocyph\Foundation\Process\ProcessOptions(timeoutSeconds: 30));
        expect($result->exitCode)->toBe(0, $result->stderr);
        foreach (['baseline', 'candidate'] as $variant) {
            $merged = json_decode((string) file_get_contents($directory . '/' . $variant . '-merged.json'), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($merged)) {
                throw new \UnexpectedValueException('Decoded benchmark or test evidence must be an array.');
            }
            \PHPUnit\Framework\Assert::assertIsArray(($merged['soak'] ?? null));
            \PHPUnit\Framework\Assert::assertIsArray(($merged['soak']['runwire'] ?? null));

            expect($merged['soak'])->toHaveKeys(['fpm', 'runwire'])
                ->and($merged['soak']['runwire']['windows'])->toHaveCount(2);
        }
        $path = $directory . '/runwire-4-10/candidate/report.json';
        $part = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($part)) {
            throw new \UnexpectedValueException('Expected a decoded JSON object.');
        }
        unset($part['soak']);
        file_put_contents($path, json_encode($part, JSON_THROW_ON_ERROR));
        expect($runner->run($command, new \Infocyph\Foundation\Process\ProcessOptions(timeoutSeconds: 30))->exitCode)->not->toBe(0);
    } finally {
        \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::removeDirectory($directory);
    }
});

/** @return array<string,mixed> */
function foundationUpgradeEvidence(): array
{
    $before = ['version' => '2.1.2', 'source_ref' => str_repeat('a', 40), 'dist_ref' => str_repeat('a', 40)];
    $after = ['version' => '3.0.0', 'source_ref' => str_repeat('c', 40), 'dist_ref' => str_repeat('c', 40)];

    return [
        'format' => 1,
        'baseline' => ['sha256' => str_repeat('b', 64), 'packages' => ['infocyph/runwire' => $before]],
        'candidate' => ['sha256' => str_repeat('c', 64), 'packages' => ['infocyph/runwire' => $after]],
        'allowed_changes' => ['infocyph/runwire' => ['before' => $before, 'after' => $after]],
    ];
}

it('accepts only explicitly reviewed whole-upgrade dependency identities', function (): void {
    $baseline = foundationHostFixture();
    $candidate = foundationHostFixture(candidate: true);
    $candidate['composer_lock_sha256'] = str_repeat('c', 64);
    $baseline['tool_sha'] = $candidate['tool_sha'] = str_repeat('d', 40);
    $baseline['fixture_id'] = $candidate['fixture_id'] = 'auth-postgres-redis';

    $comparison = Foundation4HostComparison::compare($baseline, $candidate, foundationUpgradeEvidence());
    expect($comparison['passed'])->toBeTrue()
        ->and($comparison['comparison_kind'])->toBe('dependency_upgrade')
        ->and($comparison['changed_packages'])->toBe(['infocyph/runwire']);

    expect(fn() => Foundation4HostComparison::compare($baseline, $candidate))
        ->toThrow(InvalidArgumentException::class, 'composer_lock_sha256 differs.');

    $evidence = foundationUpgradeEvidence();
    \PHPUnit\Framework\Assert::assertIsArray($evidence['allowed_changes'] ?? null);
    \PHPUnit\Framework\Assert::assertIsArray($evidence['allowed_changes']['infocyph/runwire'] ?? null);
    \PHPUnit\Framework\Assert::assertIsArray($evidence['allowed_changes']['infocyph/runwire']['after'] ?? null);
    $evidence['allowed_changes']['infocyph/runwire']['after']['version'] = '3.0.1';
    expect(fn() => Foundation4HostComparison::compare($baseline, $candidate, $evidence))
        ->toThrow(InvalidArgumentException::class, 'Unreviewed or mismatched');

    $evidence = foundationUpgradeEvidence();
    \PHPUnit\Framework\Assert::assertIsArray(($evidence['candidate'] ?? null));
    $evidence['candidate']['sha256'] = str_repeat('e', 64);
    expect(fn() => Foundation4HostComparison::compare($baseline, $candidate, $evidence))
        ->toThrow(InvalidArgumentException::class, 'does not match candidate');

    $candidate['tool_sha'] = str_repeat('e', 40);
    expect(fn() => Foundation4HostComparison::compare($baseline, $candidate, foundationUpgradeEvidence()))
        ->toThrow(InvalidArgumentException::class, 'tool_sha differs.');
});

it('rejects unreviewed extra package changes in dependency upgrade reports', function (): void {
    $baseline = foundationHostFixture();
    $candidate = foundationHostFixture(candidate: true);
    $candidate['composer_lock_sha256'] = str_repeat('c', 64);
    $baseline['tool_sha'] = $candidate['tool_sha'] = str_repeat('d', 40);
    $baseline['fixture_id'] = $candidate['fixture_id'] = 'auth-postgres-redis';
    $evidence = foundationUpgradeEvidence();
    \PHPUnit\Framework\Assert::assertIsArray(($evidence['candidate'] ?? null));
    \PHPUnit\Framework\Assert::assertIsArray(($evidence['candidate']['packages'] ?? null));

    $evidence['candidate']['packages']['psr/log'] = [
        'version' => '3.0.2',
        'source_ref' => str_repeat('d', 40),
        'dist_ref' => null,
    ];

    expect(fn() => Foundation4HostComparison::compare($baseline, $candidate, $evidence))
        ->toThrow(InvalidArgumentException::class, 'Unreviewed or mismatched');
});

it('derives deterministic Composer package/reference identities from a real lock file', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'foundation-lock-evidence-');
    \PHPUnit\Framework\Assert::assertIsString($file);
    $lock = [
        'packages' => [[
            'name' => 'infocyph/runwire',
            'version' => '3.0.0',
            'source' => ['reference' => str_repeat('c', 40)],
            'dist' => ['reference' => str_repeat('d', 40)],
        ]],
        'packages-dev' => [[
            'name' => 'phpstan/phpstan',
            'version' => '2.1.0',
        ]],
    ];

    try {
        $contents = json_encode($lock, JSON_THROW_ON_ERROR);
        file_put_contents($file, $contents);
        $manifest = \Infocyph\Foundation\Benchmarks\Support\Foundation4HostUpgradeEvidence::fromLock($file);
        expect($manifest['sha256'])->toBe(hash('sha256', $contents))
            ->and($manifest['packages']['infocyph/runwire'])->toBe([
                'version' => '3.0.0',
                'source_ref' => str_repeat('c', 40),
                'dist_ref' => str_repeat('d', 40),
            ])
            ->and($manifest['packages']['phpstan/phpstan']['source_ref'])->toBeNull();

        $lock['packages-dev'][] = $lock['packages'][0];
        file_put_contents($file, json_encode($lock, JSON_THROW_ON_ERROR));
        expect(fn() => \Infocyph\Foundation\Benchmarks\Support\Foundation4HostUpgradeEvidence::fromLock($file))
            ->toThrow(InvalidArgumentException::class, 'Duplicate Composer package');
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
});
