<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\RepresentativeBenchmark;

require dirname(__DIR__) . '/vendor/autoload.php';

$repetitions = max(1, (int) ((($__foundation154_9 = getenv('FOUNDATION_BENCHMARK_REPETITIONS')) !== false && $__foundation154_9 !== '' && $__foundation154_9 !== '0' ? $__foundation154_9 : 3)));
$warmup = max(0, (int) ((($__foundation154_10 = getenv('FOUNDATION_BENCHMARK_WARMUP')) !== false && $__foundation154_10 !== '' && $__foundation154_10 !== '0' ? $__foundation154_10 : 100)));
$operations = max(1, (int) ((($__foundation154_11 = getenv('FOUNDATION_BENCHMARK_OPERATIONS')) !== false && $__foundation154_11 !== '' && $__foundation154_11 !== '0' ? $__foundation154_11 : 1_000)));
$output = getenv('FOUNDATION_BENCHMARK_OUTPUT') ?: dirname(__DIR__) . '/build/benchmark-result.json';

(new RepresentativeBenchmark($repetitions, $warmup, $operations))->run($output);

fwrite(STDOUT, sprintf("Representative benchmark written to %s\n", $output));
