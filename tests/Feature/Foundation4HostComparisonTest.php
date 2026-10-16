<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Support\Foundation4HostComparison;

/** @return array<string, mixed> */
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
                        'requests' => 1000,
                        'successful' => 1000,
                        'failed' => 0,
                        'duration_seconds' => 60_000.0 / $rpm,
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
        'warmup_seconds' => 0,
        'hosts' => $hosts,
    ];
}

it('accepts a stable throughput change inside the strict 2 percent budget', function (): void {
    $base = foundationHostFixture();
    $candidate = foundationHostFixture(980.0);
    $candidate['sha'] = str_repeat('c', 40);

    $result = Foundation4HostComparison::compare($base, $candidate);

    expect($result['passed'])->toBeTrue()
        ->and($result['cases'])->toHaveCount(12)
        ->and($result['cases'][0]['status'])->toBe('pass')
        ->and($result['cases'][0]['delta_percent'])->toBe(-2.0);
});

it('rejects a throughput regression exceeding 2 percent', function (): void {
    $result = Foundation4HostComparison::compare(foundationHostFixture(), foundationHostFixture(979.0, true));

    expect($result['passed'])->toBeFalse()
        ->and($result['cases'][0]['status'])->toBe('regression');
});

it('rejects noisy evidence instead of silently waiving the throughput budget', function (): void {
    $candidate = foundationHostFixture(candidate: true);
    $candidate['hosts']['fpm'][0]['runs'][0]['rpm'] = 500.0;
    $candidate['hosts']['fpm'][0]['runs'][0]['duration_seconds'] = 120.0;

    $result = Foundation4HostComparison::compare(foundationHostFixture(), $candidate);

    expect($result['passed'])->toBeFalse()
        ->and($result['cases'][0]['status'])->toBe('inconclusive_variance');
});

it('rejects mismatched host identities and runtime dependencies', function (): void {
    $candidate = foundationHostFixture(candidate: true);
    $candidate['hardware_id'] = 'another-machine';

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'hardware_id differs.');

    $candidate = foundationHostFixture(candidate: true);
    $candidate['warmup_seconds'] = 30;
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
                    $run += ['authenticated' => true, 'database_queries' => 2000, 'application_cache_hits' => 1000];
                }
                unset($run);
            }
            unset($case);
        }
        unset($cases);
    }
    unset($report);
    expect(Foundation4HostComparison::compare($base, $candidate)['passed'])->toBeTrue();
    $candidate['hosts']['runwire'][0]['runs'][0]['application_cache_hits'] = 999;
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
            expect($merged['soak'])->toHaveKeys(['fpm', 'runwire'])
                ->and($merged['soak']['runwire']['windows'])->toHaveCount(2);
        }
        $path = $directory . '/runwire-4-10/candidate/report.json';
        $part = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unset($part['soak']);
        file_put_contents($path, json_encode($part, JSON_THROW_ON_ERROR));
        expect($runner->run($command, new \Infocyph\Foundation\Process\ProcessOptions(timeoutSeconds: 30))->exitCode)->not->toBe(0);
    } finally {
        \Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::removeDirectory($directory);
    }
});
