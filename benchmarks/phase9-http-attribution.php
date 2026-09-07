<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Infocyph\Foundation\Routing\WebReleaseRuntime;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseCompiler as WebrickReleaseCompiler;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Psr\Log\NullLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

function phase9StandaloneHttpHandler(): Response
{
    return Response::json(['ok' => true]);
}

function phase9FoundationHttpHandler(): Response
{
    return Response::json(['ok' => true]);
}

/** @return array{median_ns:float,ops_per_second:float,min_ns:float,max_ns:float,samples_ns:list<float>} */
function phase9HttpMeasure(callable $operation, int $operations, int $repetitions, int $warmup): array
{
    $samples = [];

    for ($repeat = 0; $repeat < $repetitions; ++$repeat) {
        for ($i = 0; $i < $warmup; ++$i) {
            if (!$operation()) {
                throw new RuntimeException('Phase 9 HTTP attribution warmup response validation failed.');
            }
        }

        $started = hrtime(true);
        for ($i = 0; $i < $operations; ++$i) {
            if (!$operation()) {
                throw new RuntimeException('Phase 9 HTTP attribution response validation failed.');
            }
        }
        $samples[] = (hrtime(true) - $started) / $operations;
    }

    sort($samples, SORT_NUMERIC);
    $median = $samples[intdiv(count($samples), 2)];

    return [
        'median_ns' => round($median, 2),
        'ops_per_second' => round(1_000_000_000 / $median, 2),
        'min_ns' => round($samples[0], 2),
        'max_ns' => round($samples[array_key_last($samples)], 2),
        'samples_ns' => array_map(static fn(float $sample): float => round($sample, 2), $samples),
    ];
}

/** @return array{delta_ns:float,percent:float} */
function phase9HttpTax(array $foundation, array $direct): array
{
    $delta = $foundation['median_ns'] - $direct['median_ns'];

    return [
        'delta_ns' => round($delta, 2),
        'percent' => round(($delta / max(0.000001, $direct['median_ns'])) * 100, 2),
    ];
}

/** @return array{delta_ns:int,percent:float} */
function phase9HttpScalarTax(int $foundation, int $direct): array
{
    $delta = $foundation - $direct;

    return [
        'delta_ns' => $delta,
        'percent' => round(($delta / max(1, $direct)) * 100, 2),
    ];
}

function phase9HttpRemove(string $directory): void
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

/** @return array<string,mixed> */
function phase9HttpChild(string $variant): array
{
    $operations = max(1_000, (int) (getenv('PHASE9_HTTP_OPERATIONS') ?: 100_000));
    $repetitions = max(3, (int) (getenv('PHASE9_HTTP_REPETITIONS') ?: 7));
    $warmup = max(100, (int) (getenv('PHASE9_HTTP_WARMUP') ?: 1_000));
    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-phase9-http-' . $variant . '-' . bin2hex(random_bytes(6));
    mkdir($root . '/bootstrap/cache', 0777, true);

    try {
        $request = Request::fake(
            headers: ['Host' => 'benchmark.test'],
            uri: 'https://benchmark.test/json',
        );

        if ($variant === 'webrick') {
            $intermixPath = $root . '/bootstrap/cache/intermix.php';
            $routerPath = $root . '/bootstrap/cache/router.php';
            $manifestPath = $root . '/bootstrap/cache/release.json';
            $environment = 'production';
            $configFingerprint = hash('sha256', 'foundation-phase9-http-attribution');
            $builder = ContainerBuilder::create('foundation.phase9.webrick');
            $builder->setEnvironment($environment);

            $compileStarted = hrtime(true);
            $release = new WebrickReleaseCompiler()->compile(
                builder: $builder,
                register: static function (Registrar $registrar): void {
                    $registrar->get('/json', 'phase9StandaloneHttpHandler');
                },
                environment: $environment,
                configFingerprint: $configFingerprint,
                intermixPath: $intermixPath,
                routerPath: $routerPath,
                releaseManifestPath: $manifestPath,
                preGlobal: [],
                postGlobal: [],
                preGlobalTags: [],
                postGlobalTags: [],
            );
            $compileNs = hrtime(true) - $compileStarted;
            $skipped = $release['intermix']['skipped'] ?? null;
            if (!is_array($skipped) || $skipped !== []) {
                throw new RuntimeException('Standalone Webrick attribution graph was not fully compiled.');
            }

            $bootStarted = hrtime(true);
            $container = $builder->productionPrevalidated(
                $intermixPath,
                (string) $release['intermix']['digest'],
            );
            $kernel = CompiledRouterKernel::fromPrevalidatedArtifact(
                log: new NullLogger(),
                matcher: FusedMatcher::make(),
                container: $container,
                artifactPath: $routerPath,
                trustedArtifactFingerprint: (string) $release['webrick']['fingerprint'],
                environment: $environment,
                configFingerprint: $configFingerprint,
            );
            $bootNs = hrtime(true) - $bootStarted;
        } elseif ($variant === 'foundation') {
            mkdir($root . '/routes', 0777, true);
            file_put_contents($root . '/routes/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/json', 'phase9FoundationHttpHandler');
PHP);

            $intermixPath = $root . '/bootstrap/cache/intermix.php';
            $routerPath = $root . '/bootstrap/cache/router.php';
            $manifestPath = $root . '/bootstrap/cache/release.json';
            $config = [
                'app' => [
                    'base_path' => $root,
                    'env' => 'production',
                    'debug' => false,
                ],
                '_config_cache' => false,
                'router' => [
                    'files' => ['web.php'],
                    'matcher' => 'fused',
                    'middleware' => [
                        'globals' => [
                            'pre' => [],
                            'post' => [],
                        ],
                    ],
                ],
            ];

            $compileStarted = hrtime(true);
            $release = new WebReleaseCompiler()->compile(
                $config,
                $intermixPath,
                $routerPath,
                $manifestPath,
                [],
            );
            $compileNs = hrtime(true) - $compileStarted;

            $bootStarted = hrtime(true);
            $runtime = WebReleaseRuntime::loadPrevalidated(
                $config,
                $manifestPath,
                (string) $release['release_runtime_manifest_sha256'],
                foundationCapabilities: [],
            );
            $kernel = $runtime->kernel;
            $bootNs = hrtime(true) - $bootStarted;
        } else {
            throw new InvalidArgumentException(sprintf('Unknown Phase 9 HTTP attribution variant "%s".', $variant));
        }

        $warmRequest = phase9HttpMeasure(
            static function () use ($kernel, $request): bool {
                $response = $kernel->handle($request);

                return $response->getStatusCode() === 200
                    && (string) $response->getBody() === '{"ok":true}';
            },
            $operations,
            $repetitions,
            $warmup,
        );

        return [
            'variant' => $variant,
            'compile_ns' => $compileNs,
            'runtime_boot_ns' => $bootNs,
            'warm_request' => $warmRequest,
            'memory' => [
                'final_bytes' => memory_get_usage(true),
                'peak_bytes' => memory_get_peak_usage(true),
            ],
        ];
    } finally {
        phase9HttpRemove($root);
    }
}

/** @return array<string,mixed> */
function phase9HttpSpawn(string $variant): array
{
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--child', $variant],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException(sprintf('Unable to start Phase 9 HTTP "%s" benchmark process.', $variant));
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException(sprintf(
            'Phase 9 HTTP "%s" benchmark failed with exit %d: %s',
            $variant,
            $exit,
            trim((string) $stderr),
        ));
    }

    $decoded = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('Phase 9 HTTP "%s" benchmark returned invalid JSON.', $variant));
    }

    return $decoded;
}

if (($argv[1] ?? null) === '--child') {
    $result = phase9HttpChild((string) ($argv[2] ?? ''));
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return;
}

$direct = phase9HttpSpawn('webrick');
$foundation = phase9HttpSpawn('foundation');
$result = [
    'schema' => 1,
    'captured_at_utc' => gmdate(DATE_ATOM),
    'runtime' => [
        'php' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY,
        'opcache_cli' => filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL),
    ],
    'source' => [
        'foundation_commit' => getenv('GITHUB_SHA') ?: 'working-tree',
        'webrick_version' => InstalledVersions::getPrettyVersion('infocyph/webrick'),
        'webrick_reference' => InstalledVersions::getReference('infocyph/webrick'),
        'intermix_version' => InstalledVersions::getPrettyVersion('infocyph/intermix'),
        'intermix_reference' => InstalledVersions::getReference('infocyph/intermix'),
    ],
    'configuration' => [
        'operations' => max(1_000, (int) (getenv('PHASE9_HTTP_OPERATIONS') ?: 100_000)),
        'repetitions' => max(3, (int) (getenv('PHASE9_HTTP_REPETITIONS') ?: 7)),
        'warmup' => max(100, (int) (getenv('PHASE9_HTTP_WARMUP') ?: 1_000)),
        'matcher' => 'fused',
        'route' => 'GET /json -> {"ok":true}',
        'isolation' => 'standalone Webrick and Foundation execute in separate PHP processes',
    ],
    'standalone_webrick' => $direct,
    'foundation' => $foundation,
    'attribution' => [
        'warm_request_tax' => phase9HttpTax($foundation['warm_request'], $direct['warm_request']),
        'compile_tax' => phase9HttpScalarTax((int) $foundation['compile_ns'], (int) $direct['compile_ns']),
        'runtime_boot_tax' => phase9HttpScalarTax((int) $foundation['runtime_boot_ns'], (int) $direct['runtime_boot_ns']),
    ],
];

$output = getenv('PHASE9_HTTP_OUTPUT') ?: dirname(__DIR__) . '/build/phase9-http-attribution.json';
$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException(sprintf('Unable to create benchmark output directory "%s".', $directory));
}
file_put_contents(
    $output,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    LOCK_EX,
);
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
