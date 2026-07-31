<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\RepresentativeBenchmark;
use Infocyph\PHPForge\Support\BenchmarkResult;

it('produces validated representative results from complete Foundation requests', function (): void {
    $output = sys_get_temp_dir() . '/foundation-benchmark-' . bin2hex(random_bytes(5)) . '.json';

    try {
        $document = (new RepresentativeBenchmark(
            repetitions: 1,
            warmupOperations: 1,
            operations: 5,
        ))->run($output);
        $validation = (new BenchmarkResult())->load($output);

        expect($validation['errors'])->toBe([])
            ->and(array_column($document['workloads'], 'name'))->toBe([
                'minimal-json-warm',
                'route-selected-array-session-warm',
            ]);

        foreach ($document['workloads'] as $workload) {
            expect($workload['result']['successful_operations'])->toBe(5)
                ->and($workload['result']['failed_operations'])->toBe(0);
        }
    } finally {
        if (is_file($output)) {
            unlink($output);
        }
    }
});
