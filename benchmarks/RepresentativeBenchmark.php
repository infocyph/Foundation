<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Foundation;
use Infocyph\Webrick\Request\Request;
use Throwable;

final readonly class RepresentativeBenchmark
{
    /**
     * @param int $repetitions Number of independently measured samples.
     * @param int $warmupOperations Unmeasured operations performed before each sample.
     * @param int $operations Measured operations performed in each sample.
     */
    public function __construct(
        private int $repetitions = 3,
        private int $warmupOperations = 100,
        private int $operations = 1_000,
    ) {
        if ($this->repetitions < 1 || $this->warmupOperations < 0 || $this->operations < 1) {
            throw new \InvalidArgumentException('Benchmark repetitions and operations must be positive.');
        }
    }

    /**
     * @param string $outputPath Destination for the PHPForge benchmark-result document.
     * @return array<string, mixed>
     */
    public function run(string $outputPath): array
    {
        $basePath = sys_get_temp_dir() . '/foundation-representative-' . bin2hex(random_bytes(6));
        $routesPath = $basePath . '/routes';
        if (!mkdir($routesPath, 0775, true) && !is_dir($routesPath)) {
            throw new \RuntimeException(sprintf('Unable to create benchmark directory "%s".', $routesPath));
        }
        $routeFile = $routesPath . '/web.php';
        $written = file_put_contents($routeFile, <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Foundation\Session\BrowserSession;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/json', static fn(): Response => Response::json(['ok' => true]));
Router::get('/session', static function (BrowserSession $session): Response {
    $session->put('visited', true);

    return Response::json(['session' => true]);
}, ['middleware' => ['session']]);
PHP);
        if ($written === false) {
            $this->removeDirectory($basePath);

            throw new \RuntimeException(sprintf('Unable to write benchmark route file "%s".', $routeFile));
        }

        try {
            $application = $this->application($basePath);
            $document = [
                'schema_version' => 1,
                'generated_at' => date(DATE_RFC3339),
                'environment' => $this->environment(),
                'workloads' => [
                    $this->measure(
                        'minimal-json-warm',
                        'persistent-worker',
                        $this->requestOperation($application, '/json', '{"ok":true}'),
                        ['route' => '/json', 'middleware' => [], 'response_bytes' => 11],
                    ),
                    $this->measure(
                        'route-selected-array-session-warm',
                        'persistent-worker',
                        $this->requestOperation($application, '/session', '{"session":true}'),
                        ['route' => '/session', 'middleware' => ['session'], 'session_driver' => 'array'],
                    ),
                ],
            ];
            $this->write($outputPath, $document);

            return $document;
        } finally {
            $this->removeDirectory($basePath);
        }
    }

    /**
     * @param string $basePath Temporary application root.
     */
    private function application(string $basePath): Application
    {
        return Foundation::web([
            'app' => ['base_path' => $basePath],
            '_config_cache' => false,
            'router' => [
                'cache' => false,
                'files' => ['web.php'],
                'middleware' => [
                    'globals' => [
                        'pre' => [],
                        'post' => [],
                    ],
                ],
            ],
            'session' => ['driver' => 'array'],
        ])->boot();
    }

    private function cpuModel(): string
    {
        $contents = is_file('/proc/cpuinfo') ? file_get_contents('/proc/cpuinfo') : false;
        if (is_string($contents)
            && preg_match('/^model name\\s*:\\s*(.+)$/mi', $contents, $matches) === 1
        ) {
            return trim($matches[1]);
        }

        return php_uname('m');
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        $stable = getenv('FOUNDATION_BENCHMARK_STABLE') === '1';
        $fingerprint = getenv('FOUNDATION_BENCHMARK_FINGERPRINT');
        if ($stable && (!is_string($fingerprint) || $fingerprint === '')) {
            throw new \RuntimeException(
                'FOUNDATION_BENCHMARK_FINGERPRINT is required when FOUNDATION_BENCHMARK_STABLE=1.',
            );
        }

        $cpuModel = $this->cpuModel();
        $extensions = get_loaded_extensions();
        sort($extensions);
        $fingerprint = is_string($fingerprint) && $fingerprint !== ''
            ? $fingerprint
            : hash('sha256', implode('|', [
                PHP_VERSION,
                PHP_SAPI,
                PHP_OS_FAMILY,
                php_uname('r'),
                $cpuModel,
                implode(',', $extensions),
            ]));

        return [
            'stable' => $stable,
            'fingerprint' => $fingerprint,
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'operating_system' => php_uname('s') . ' ' . php_uname('r'),
            'cpu_model' => $cpuModel,
            'memory_limit' => ini_get('memory_limit') ?: '-1',
            'opcache' => extension_loaded('Zend OPcache') && (bool) ini_get('opcache.enable_cli'),
            'jit' => (string) ini_get('opcache.jit') !== '' && (string) ini_get('opcache.jit') !== '0',
            'xdebug' => extension_loaded('xdebug'),
            'extensions' => $extensions,
            'runner' => 'foundation-representative 1',
            'release' => getenv('GITHUB_SHA') ?: 'working-tree',
        ];
    }

    /**
     * @param string $name Stable workload name.
     * @param string $type PHPForge workload type.
     * @param callable():bool $operation One complete validated operation.
     * @param array<string, mixed> $metadata Reproduction metadata.
     * @return array<string, mixed>
     */
    private function measure(string $name, string $type, callable $operation, array $metadata): array
    {
        $latencies = [];
        $sampleRpms = [];
        $successful = 0;
        $failed = 0;
        $duration = 0.0;
        $memoryTotal = 0.0;
        $memoryPeak = 0.0;
        $memoryGrowth = 0.0;

        for ($sample = 0; $sample < $this->repetitions; $sample++) {
            for ($warmup = 0; $warmup < $this->warmupOperations; $warmup++) {
                $operation();
            }

            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $memoryBefore = memory_get_usage(true);
            $sampleSuccessful = 0;
            $sampleStarted = hrtime(true);

            for ($iteration = 0; $iteration < $this->operations; $iteration++) {
                $started = hrtime(true);

                try {
                    if ($operation()) {
                        ++$successful;
                        ++$sampleSuccessful;
                    } else {
                        ++$failed;
                    }
                } catch (Throwable) {
                    ++$failed;
                }
                $latencies[] = (hrtime(true) - $started) / 1_000_000;
            }

            $sampleDuration = max(0.000001, (hrtime(true) - $sampleStarted) / 1_000_000_000);
            $duration += $sampleDuration;
            $sampleRpms[] = ($sampleSuccessful / $sampleDuration) * 60;
            $memoryAfter = memory_get_usage(true);
            $memoryTotal += ($memoryBefore + $memoryAfter) / 2 / 1_048_576;
            $memoryPeak = max($memoryPeak, memory_get_peak_usage(true) / 1_048_576);
            $memoryGrowth = max($memoryGrowth, max(0, $memoryAfter - $memoryBefore) / 1_048_576);
        }

        sort($latencies);
        $attempted = $this->operations * $this->repetitions;
        $spread = $this->spread($sampleRpms);
        $stableEnvironment = getenv('FOUNDATION_BENCHMARK_STABLE') === '1';

        return [
            'name' => $name,
            'type' => $type,
            'metadata' => array_merge($metadata, [
                'command' => 'composer benchmark:representative',
                'successful_rpm_regression_budget_percent' => 2,
                'maximum_error_rate' => 0,
                'maximum_timeout_rate' => 0,
                'response_validation' => 'exact status and JSON body',
            ]),
            'repetitions' => $this->repetitions,
            'warmup_operations' => $this->warmupOperations * $this->repetitions,
            'duration_seconds' => $duration,
            'concurrency' => 1,
            'result' => [
                'attempted_operations' => $attempted,
                'successful_operations' => $successful,
                'failed_operations' => $failed,
                'timeouts' => 0,
                'successful_rpm' => $duration > 0 ? ($successful / $duration) * 60 : 0.0,
                'error_rate' => $attempted > 0 ? $failed / $attempted : 0.0,
                'latency_ms' => [
                    'minimum' => $latencies[0] ?? null,
                    'average' => $latencies === [] ? null : array_sum($latencies) / count($latencies),
                    'p50' => $this->percentile($latencies, 0.50),
                    'p95' => $this->percentile($latencies, 0.95),
                    'p99' => $this->percentile($latencies, 0.99),
                    'maximum' => $latencies[array_key_last($latencies)] ?? null,
                ],
                'cpu' => [
                    'average_percent' => null,
                    'peak_percent' => null,
                ],
                'memory' => [
                    'average_mb' => $memoryTotal / $this->repetitions,
                    'peak_mb' => $memoryPeak,
                    'growth_mb' => $memoryGrowth,
                ],
                'stability' => [
                    'status' => $stableEnvironment
                        ? ($spread <= 5.0 ? 'stable' : 'unstable')
                        : 'unverified',
                    'spread_percent' => $spread,
                ],
            ],
        ];
    }

    /**
     * @param list<float> $values Sorted measured values.
     * @param float $percentile Requested percentile from zero through one.
     */
    private function percentile(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        $index = max(0, min(count($values) - 1, (int) ceil(count($values) * $percentile) - 1));

        return $values[$index];
    }

    /**
     * @param string $directory Temporary benchmark application root.
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }

    /**
     * @param Application $application Warm Foundation application.
     * @param string $path Request path.
     * @param string $expectedBody Exact expected JSON body.
     * @return callable():bool
     */
    private function requestOperation(Application $application, string $path, string $expectedBody): callable
    {
        $request = Request::fake(
            headers: ['Host' => 'benchmark.test'],
            uri: 'https://benchmark.test' . $path,
        );

        return static function () use ($application, $request, $expectedBody): bool {
            $response = $application->handle($request);

            return $response->getStatusCode() === 200
                && (string) $response->getBody() === $expectedBody;
        };
    }

    /**
     * @param list<float> $values Per-repetition successful-RPM samples.
     */
    private function spread(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $median = $this->percentile($values, 0.50) ?? 0.0;

        return $median > 0.0 ? ((max($values) - min($values)) / $median) * 100 : 0.0;
    }

    /**
     * @param string $outputPath Destination for the benchmark result.
     * @param array<string, mixed> $document Complete PHPForge benchmark document.
     */
    private function write(string $outputPath, array $document): void
    {
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create benchmark output directory "%s".', $directory));
        }

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $temporary = tempnam($directory, '.benchmark-');
        if (!is_string($temporary)) {
            throw new \RuntimeException(sprintf('Unable to write benchmark result "%s".', $outputPath));
        }

        try {
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $outputPath)) {
                throw new \RuntimeException(sprintf('Unable to write benchmark result "%s".', $outputPath));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
