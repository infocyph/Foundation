<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\RepresentativeBenchmark;

require dirname(__DIR__) . '/vendor/autoload.php';

$repetitions = max(1, (int) (getenv('FOUNDATION_BENCHMARK_REPETITIONS') ?: 3));
$warmup = max(0, (int) (getenv('FOUNDATION_BENCHMARK_WARMUP') ?: 100));
$operations = max(1, (int) (getenv('FOUNDATION_BENCHMARK_OPERATIONS') ?: 1_000));
$output = getenv('FOUNDATION_BENCHMARK_OUTPUT') ?: dirname(__DIR__) . '/build/benchmark-result.json';

(new RepresentativeBenchmark($repetitions, $warmup, $operations))->run($output);

fwrite(STDOUT, sprintf("Representative benchmark written to %s\n", $output));
