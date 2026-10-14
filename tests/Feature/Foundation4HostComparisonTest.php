<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Support\Foundation4HostComparison;

/** @return array<string, mixed> */
function foundationHostFixture(float $rpm = 1000.0): array
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
        'sha' => str_repeat('a', 40),
        'hardware_id' => 'same-dedicated-machine',
        'php' => '8.4.26',
        'protocol' => 'HTTP/1.1 with per-response status/body checks',
        'workload_profile' => 'auth-db-cache-v1',
        'composer_lock_sha256' => str_repeat('b', 64),
        'request_count' => 1000,
        'repetitions' => 5,
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
    $result = Foundation4HostComparison::compare(foundationHostFixture(), foundationHostFixture(979.0));

    expect($result['passed'])->toBeFalse()
        ->and($result['cases'][0]['status'])->toBe('regression');
});

it('rejects noisy evidence instead of silently waiving the throughput budget', function (): void {
    $candidate = foundationHostFixture();
    $candidate['hosts']['fpm'][0]['runs'][0]['rpm'] = 500.0;
    $candidate['hosts']['fpm'][0]['runs'][0]['duration_seconds'] = 120.0;

    $result = Foundation4HostComparison::compare(foundationHostFixture(), $candidate);

    expect($result['passed'])->toBeFalse()
        ->and($result['cases'][0]['status'])->toBe('inconclusive_variance');
});

it('rejects mismatched host identities and runtime dependencies', function (): void {
    $candidate = foundationHostFixture();
    $candidate['hardware_id'] = 'another-machine';

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'hardware_id differs.');

    $candidate = foundationHostFixture();
    $candidate['composer_lock_sha256'] = str_repeat('d', 64);

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'composer_lock_sha256 differs.');
});

it('rejects short runs, invalid responses and missing benchmark scenarios', function (): void {
    $candidate = foundationHostFixture();
    $candidate['hosts']['runwire'][5]['runs'][0]['duration_seconds'] = 0.2;

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'Invalid or unsustained evidence');

    $candidate = foundationHostFixture();
    $candidate['hosts']['runwire'][5]['runs'][0]['successful'] = 999;

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'Invalid or unsustained evidence');

    $candidate = foundationHostFixture();
    array_pop($candidate['hosts']['runwire']);

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'scenario matrices are different.');
});

it('refuses to treat short shared-runner smoke evidence as a certified comparison', function (): void {
    $candidate = foundationHostFixture();
    $candidate['hardware_id'] = null;

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'incomplete or non-certifiable');

    $candidate = foundationHostFixture();
    $candidate['repetitions'] = 3;

    expect(fn(): array => Foundation4HostComparison::compare(foundationHostFixture(), $candidate))
        ->toThrow(InvalidArgumentException::class, 'incomplete or non-certifiable');
});
