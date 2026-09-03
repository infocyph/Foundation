<?php

declare(strict_types=1);

use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Infocyph\Foundation\Routing\WebReleaseRuntime;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RuntimeAdapterInterface;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;

require dirname(__DIR__) . '/vendor/autoload.php';

final class FoundationMaintenanceBenchmarkAdapter implements RuntimeAdapterInterface
{
    public int $requestMaterializations = 0;

    private readonly RuntimeCapabilities $runtimeCapabilities;

    public function __construct()
    {
        $this->runtimeCapabilities = new RuntimeCapabilities(
            name: 'foundation-maintenance-benchmark',
            persistent: true,
            concurrent: true,
            nativeStreaming: true,
            nativeFile: true,
        );
    }

    public function capabilities(): RuntimeCapabilities
    {
        return $this->runtimeCapabilities;
    }

    public function context(
        mixed $nativeRequest = null,
        mixed $nativeResponse = null,
        bool $withHost = false,
    ): RuntimeRequestContext {
        unset($nativeRequest, $nativeResponse);
        $host = $withHost ? 'benchmark.test' : '*';

        return new RuntimeRequestContext(
            new RoutingInput('GET', '/benchmark', $host),
            function (): Request {
                ++$this->requestMaterializations;

                return Request::fake(
                    headers: ['Host' => 'benchmark.test'],
                    uri: 'https://benchmark.test/benchmark',
                );
            },
            $this->runtimeCapabilities,
        );
    }

    public function write(Response $response, RuntimeRequestContext $context): void
    {
        unset($context);
        if ($response->getStatusCode() !== 200 || $response->getStringBody() !== 'ok') {
            throw new LogicException('Maintenance benchmark produced an invalid response.');
        }
    }
}

final class FoundationMaintenanceBenchmarkHandler
{
    public function __invoke(): Response
    {
        return Response::create('ok');
    }
}

/** @return array<string,mixed> */
function foundationMaintenanceBenchmarkMode(string $mode): array
{
    $enabled = $mode === 'enabled';
    if (!$enabled && $mode !== 'disabled') {
        throw new InvalidArgumentException('Benchmark mode must be enabled or disabled.');
    }

    $operations = max(1, (int) (getenv('FOUNDATION_MAINTENANCE_BENCHMARK_OPERATIONS') ?: 25_000));
    $warmup = max(0, (int) (getenv('FOUNDATION_MAINTENANCE_BENCHMARK_WARMUP') ?: 2_500));
    $repetitions = max(1, (int) (getenv('FOUNDATION_MAINTENANCE_BENCHMARK_REPETITIONS') ?: 5));
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-maintenance-benchmark-' . $mode . '-' . bin2hex(random_bytes(5));
    mkdir($project . '/routes', 0777, true);
    mkdir($project . '/bootstrap/cache', 0777, true);
    file_put_contents($project . '/routes/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/benchmark', FoundationMaintenanceBenchmarkHandler::class);
PHP);

    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'operations' => [
            'maintenance' => [
                'driver' => 'file',
                'path' => 'storage/framework/maintenance.json',
                'refresh_milliseconds' => 1_000,
                'web' => ['enabled' => $enabled],
            ],
        ],
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $manifest = $project . '/bootstrap/cache/release.json';
    $release = new WebReleaseCompiler()->compile(
        $config,
        $project . '/bootstrap/cache/intermix.php',
        $project . '/bootstrap/cache/router.php',
        $manifest,
    );
    $trustedSha256 = $release['release_runtime_manifest_sha256'] ?? null;
    if (!is_string($trustedSha256)) {
        throw new RuntimeException('Maintenance benchmark release digest is unavailable.');
    }

    $adapter = new FoundationMaintenanceBenchmarkAdapter();
    $runtime = WebReleaseRuntime::loadPrevalidated($config, $manifest, $trustedSha256, $adapter);
    $samples = [];

    try {
        for ($i = 0; $i < $warmup; ++$i) {
            $runtime->server->handle();
        }

        for ($sample = 0; $sample < $repetitions; ++$sample) {
            $started = hrtime(true);
            for ($i = 0; $i < $operations; ++$i) {
                $runtime->server->handle();
            }
            $samples[] = (hrtime(true) - $started) / $operations;
        }
    } finally {
        foundationMaintenanceBenchmarkRemove($project);
    }

    sort($samples);
    $medianNs = $samples[(int) floor((count($samples) - 1) / 2)];

    return [
        'mode' => $mode,
        'operations_per_sample' => $operations,
        'warmup_operations' => $warmup,
        'repetitions' => $repetitions,
        'median_ns_per_operation' => $medianNs,
        'median_rpm' => 60_000_000_000 / $medianNs,
        'request_materializations' => $adapter->requestMaterializations,
        'samples_ns_per_operation' => $samples,
    ];
}

function foundationMaintenanceBenchmarkRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

function foundationMaintenanceBenchmarkChild(string $mode): array
{
    $command = [PHP_BINARY, __FILE__, '--mode=' . $mode];
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start maintenance benchmark child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !is_string($stdout)) {
        throw new RuntimeException(sprintf(
            'Maintenance benchmark %s child failed: %s',
            $mode,
            is_string($stderr) ? trim($stderr) : '',
        ));
    }

    $result = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException('Maintenance benchmark child returned an invalid result.');
    }

    return $result;
}

$mode = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--mode=')) {
        $mode = substr($argument, strlen('--mode='));
    }
}

if (is_string($mode) && $mode !== '') {
    fwrite(STDOUT, json_encode(foundationMaintenanceBenchmarkMode($mode), JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

$disabled = foundationMaintenanceBenchmarkChild('disabled');
$enabled = foundationMaintenanceBenchmarkChild('enabled');
$disabledNs = (float) ($disabled['median_ns_per_operation'] ?? 0.0);
$enabledNs = (float) ($enabled['median_ns_per_operation'] ?? 0.0);
if ($disabledNs <= 0.0 || $enabledNs <= 0.0) {
    throw new RuntimeException('Maintenance benchmark did not produce positive timings.');
}
$overhead = (($enabledNs / $disabledNs) - 1.0) * 100.0;
$threshold = 5.0;
$document = [
    'schema_version' => 1,
    'generated_at' => gmdate(DATE_ATOM),
    'php_version' => PHP_VERSION,
    'disabled' => $disabled,
    'enabled' => $enabled,
    'enabled_overhead_percent' => $overhead,
    'wb5_review_threshold_percent' => $threshold,
    'wb5_decision' => $overhead > $threshold
        ? 'pre-routing-gate-justified'
        : 'retain-compiled-middleware-gate',
];
$output = getenv('FOUNDATION_MAINTENANCE_BENCHMARK_OUTPUT') ?: dirname(__DIR__) . '/build/maintenance-overhead.json';
$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException(sprintf('Unable to create benchmark output directory "%s".', $directory));
}
file_put_contents($output, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

fwrite(STDOUT, sprintf(
    "Maintenance gate: disabled %.1f ns/op, enabled %.1f ns/op, overhead %.2f%%, decision %s\n",
    $disabledNs,
    $enabledNs,
    $overhead,
    $document['wb5_decision'],
));
