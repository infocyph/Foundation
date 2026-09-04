<?php

declare(strict_types=1);

use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Infocyph\Foundation\Routing\WebReleaseRuntime;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\ResponseWriterSupport;
use Infocyph\Webrick\Runtime\Http\RuntimeAdapterInterface;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Runtime\Http\SapiRuntimeAdapter;

final class FoundationPersistentEmissionAdapter implements RuntimeAdapterInterface
{
    public int $requestMaterializations = 0;

    public int $writes = 0;

    /** @var list<string> */
    public array $bodies = [];

    public string $path = '/file';

    private readonly RuntimeCapabilities $runtimeCapabilities;

    public function __construct()
    {
        $this->runtimeCapabilities = new RuntimeCapabilities(
            name: 'foundation-test-persistent',
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
        $host = $withHost ? 'runtime.test' : '*';

        return new RuntimeRequestContext(
            new RoutingInput('GET', $this->path, $host),
            function (): Request {
                ++$this->requestMaterializations;

                return Request::fake(
                    headers: ['Host' => 'runtime.test'],
                    uri: 'https://runtime.test' . $this->path,
                );
            },
            $this->runtimeCapabilities,
        );
    }

    public function write(Response $response, RuntimeRequestContext $context): void
    {
        ++$this->writes;
        if (!ResponseWriterSupport::allowsBody($response, $context)) {
            $this->bodies[] = '';

            return;
        }

        $body = '';
        foreach (ResponseWriterSupport::chunks($response) as $chunk) {
            $body .= $chunk;
        }
        $this->bodies[] = $body;
    }
}

final class FoundationRuntimeEmissionFixture
{
    public static string $file = '';
}

function foundationRuntimeEmissionFile(): Response
{
    return Response::download(FoundationRuntimeEmissionFixture::$file);
}

function foundationRuntimeEmissionStream(): Response
{
    return Response::stream(static fn(): iterable => ['persistent-', 'stream']);
}

it('emits file and stream bodies through the real SAPI response writer', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'foundation-sapi-file-');
    if (!is_string($file)) {
        throw new RuntimeException('Unable to allocate SAPI response fixture.');
    }
    file_put_contents($file, 'sapi-file-body');
    try {
        $fileBody = foundationSapiEmission($file, false);
        $streamBody = foundationSapiEmission($file, true);

        expect($fileBody)->toBe('sapi-file-body')
            ->and($streamBody)->toBe('sapi-stream')
            ->and(SapiRuntimeAdapter::current()->capabilities()->nativeStreaming)->toBeTrue();
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

function foundationSapiEmission(string $file, bool $stream): string
{
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    $response = $stream
        ? "Response::stream(static fn(): iterable => ['sapi-', 'stream'])"
        : 'Response::download(' . var_export($file, true) . ')';
    $source = sprintf(<<<'PHP'
require %s;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;
use Infocyph\Webrick\Runtime\Http\SapiRuntimeAdapter;

$adapter = SapiRuntimeAdapter::current();
$context = new RuntimeRequestContext(
    new RoutingInput('GET', '/download'),
    static fn(): Request => Request::fake(
        headers: ['Host' => 'runtime.test'],
        uri: 'https://runtime.test/download',
    ),
    $adapter->capabilities(),
);
$adapter->write(%s, $context);
PHP, var_export($autoload, true), $response);
    $result = new ProcessRunner()->run(
        [PHP_BINARY, '-r', $source],
        new ProcessOptions(timeoutSeconds: 10.0),
    );
    if (!$result->successful() || $result->stderr !== '') {
        throw new RuntimeException(sprintf(
            'SAPI emission probe failed with exit %d: %s',
            $result->exitCode,
            $result->stderr,
        ));
    }

    return $result->stdout;
}

it('lets RuntimeServer own exactly one persistent native write for file and stream responses', function (): void {
    $project = foundationRuntimeEmissionProject();
    $file = $project . '/payload.txt';
    file_put_contents($file, 'persistent-file-body');
    FoundationRuntimeEmissionFixture::$file = $file;

    $manifest = $project . '/bootstrap/cache/release.json';
    $intermix = $project . '/bootstrap/cache/intermix.php';
    $router = $project . '/bootstrap/cache/router.php';
    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];

    try {
        $release = new WebReleaseCompiler()->compile(
            $config,
            $intermix,
            $router,
            $manifest,
            capabilities: [],
        );
        $trustedSha256 = $release['release_runtime_manifest_sha256'] ?? null;
        expect($trustedSha256)->toBeString();

        $adapter = new FoundationPersistentEmissionAdapter();
        $runtime = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            $trustedSha256,
            $adapter,
            foundationCapabilities: [],
        );

        $direct = $runtime->kernel->handle(Request::fake(
            headers: ['Host' => 'runtime.test'],
            uri: 'https://runtime.test/file',
        ));
        expect((string) $direct->getBody())->toBe('persistent-file-body')
            ->and($adapter->writes)->toBe(0);

        $adapter->path = '/file';
        $runtime->server->handle();
        $adapter->path = '/stream';
        $runtime->server->handle();

        expect($adapter->writes)->toBe(2)
            ->and($adapter->bodies)->toBe(['persistent-file-body', 'persistent-stream'])
            ->and($adapter->requestMaterializations)->toBe(0)
            ->and($runtime->capabilities)->toBe($adapter->capabilities())
            ->and($runtime->capabilities->persistent)->toBeTrue()
            ->and($runtime->capabilities->nativeFile)->toBeTrue()
            ->and($runtime->capabilities->nativeStreaming)->toBeTrue();
    } finally {
        FoundationRuntimeEmissionFixture::$file = '';
        foundationResetWebrickProductionRegistries();
        foundationRuntimeEmissionRemove($project);
    }
});

function foundationRuntimeEmissionProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-runtime-emission-' . bin2hex(random_bytes(5));
    mkdir($project . '/routes', 0777, true);
    mkdir($project . '/bootstrap/cache', 0777, true);
    file_put_contents($project . '/routes/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/file', 'foundationRuntimeEmissionFile');
Router::get('/stream', 'foundationRuntimeEmissionStream');
PHP);

    return $project;
}

function foundationRuntimeEmissionRemove(string $directory): void
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
