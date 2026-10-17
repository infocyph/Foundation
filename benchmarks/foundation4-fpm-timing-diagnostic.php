<?php

declare(strict_types=1);

$replace = static function (string $source, string $before, string $after): string {
    if (substr_count($source, $before) !== 1) {
        throw new RuntimeException('Diagnostic instrumentation target is not unique: ' . $before);
    }

    return str_replace($before, $after, $source);
};

foreach (['baseline', 'candidate'] as $variant) {
    $runtimePath = $variant . '/src/Routing/WebReleaseRuntime.php';
    $source = file_get_contents($runtimePath);
    if ($source === false) {
        throw new RuntimeException('Unable to read the diagnostic runtime.');
    }
    $source = $replace($source, '$graph = new WebGraphFactory()->compose($config, $foundationCapabilities);', '$diagnosticStart = hrtime(true);' . "\n        " . '$graph = new WebGraphFactory()->compose($config, $foundationCapabilities);' . "\n        " . '$diagnosticGraph = hrtime(true);');
    $source = $replace($source, '$logger = $container->get(LoggerInterface::class);', '$diagnosticContainer = hrtime(true);' . "\n        " . '$logger = $container->get(LoggerInterface::class);');
    $source = $replace($source, 'return new self($container, $kernel, $server, $capabilities);', '$_SERVER["FOUNDATION_BENCH_TIMINGS"] = ["boot_graph" => $diagnosticGraph - $diagnosticStart, "boot_container" => $diagnosticContainer - $diagnosticGraph, "boot_kernel" => hrtime(true) - $diagnosticContainer];' . "\n        " . 'return new self($container, $kernel, $server, $capabilities);');
    file_put_contents($runtimePath, $source);

    $workloadPath = $variant . '/benchmarks/Foundation4AuthenticatedWorkload.php';
    $source = file_get_contents($workloadPath);
    if ($source === false) {
        throw new RuntimeException('Unable to read the diagnostic workload.');
    }
    $source = $replace($source, '$connection = $database->connection();', '$diagnosticHandler = hrtime(true);' . "\n        " . '$connection = $database->connection();');
    $source = $replace($source, '$principal = $resolver->resolve($request);', '$diagnosticConnection = hrtime(true);' . "\n        " . '$principal = $resolver->resolve($request);' . "\n        " . '$diagnosticPrincipal = hrtime(true);');
    $source = $replace($source, '$auth->authorizer()->authorize($principal, \'benchmark.read\');', '$auth->authorizer()->authorize($principal, \'benchmark.read\');' . "\n        " . '$diagnosticAuthorization = hrtime(true);');
    $source = $replace($source, '$cached = $cache->store()->get(\'item-42\');', '$diagnosticSql = hrtime(true);' . "\n        " . '$cached = $cache->store()->get(\'item-42\');' . "\n        " . '$diagnosticCache = hrtime(true);');
    $source = $replace($source, "->withHeader('X-Foundation-Benchmark-Cache-Hits', '1');", "->withHeader('X-Foundation-Benchmark-Cache-Hits', '1')" . "\n            " . '->withHeader("X-Foundation-Benchmark-Timing", json_encode($_SERVER["FOUNDATION_BENCH_TIMINGS"] + ["handler_connection" => $diagnosticConnection - $diagnosticHandler, "handler_principal" => $diagnosticPrincipal - $diagnosticConnection, "handler_authorization" => $diagnosticAuthorization - $diagnosticPrincipal, "handler_sql" => $diagnosticSql - $diagnosticAuthorization, "handler_cache" => $diagnosticCache - $diagnosticSql], JSON_THROW_ON_ERROR));');
    file_put_contents($workloadPath, $source);

    $loadPath = $variant . '/benchmarks/foundation4-host-load.php';
    $source = file_get_contents($loadPath);
    if ($source === false) {
        throw new RuntimeException('Unable to read the diagnostic load driver.');
    }
    $source = $replace($source, '$totalQueries = 0;', '$phaseTotals = [];' . "\n" . '$totalQueries = 0;');
    $source = $replace($source, '$totalQueries += (int) $queryMatch[1];', <<<'INSERT'
$timingHeader = preg_match('/^X-Foundation-Benchmark-Timing:\s*([^\r\n]+)\r?$/im', $headers, $timingMatch);
                    if ($timingHeader !== 1) {
                        throw new RuntimeException('Missing phase timing evidence.');
                    }
                    $timings = json_decode($timingMatch[1], true, flags: JSON_THROW_ON_ERROR);
                    foreach ($timings as $phase => $nanoseconds) {
                        if (!is_int($nanoseconds) || $nanoseconds < 0) {
                            throw new RuntimeException('Invalid diagnostic phase duration.');
                        }
                        $phaseTotals[$phase] = ($phaseTotals[$phase] ?? 0) + $nanoseconds;
                    }
                    $totalQueries += (int) $queryMatch[1];
INSERT);
    $source = $replace($source, "'protocol' => 'HTTP/1.1',", "'phase_mean_us' => array_map(static fn(int \$nanoseconds): float => \$nanoseconds / max(1, \$finished) / 1000, \$phaseTotals)," . "\n    " . "'protocol' => 'HTTP/1.1',");
    file_put_contents($loadPath, $source);
}
