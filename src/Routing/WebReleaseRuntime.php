<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Http\Response\ExceptionRenderer;
use Infocyph\Foundation\Logging\HttpExceptionLogger;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseCompiler as WebrickReleaseCompiler;
use Infocyph\Webrick\Router\Build\ReleaseManifestLoader;
use Infocyph\Webrick\Router\Build\RouterArtifactLoader;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Runtime\Http\RuntimeAdapterInterface;
use Infocyph\Webrick\Runtime\Http\RuntimeCapabilities;
use Infocyph\Webrick\Runtime\Http\RuntimeServer;
use Infocyph\Webrick\Runtime\Http\SapiRuntimeAdapter;
use Psr\Log\LoggerInterface;
use Throwable;

/** Verified compiled Foundation/Webrick production runtime. */
final readonly class WebReleaseRuntime
{
    public function __construct(
        public ProductionContainer $container,
        public CompiledRouterKernel $kernel,
        public RuntimeServer $server,
        public RuntimeCapabilities $capabilities,
    ) {}

    /**
     * @param array<string, mixed> $config
     * @param array<int|string, mixed>|null $foundationCapabilities Must match compile-time topology when explicit.
     */
    public static function load(
        array $config,
        string $releaseManifestPath,
        ?RuntimeAdapterInterface $adapter = null,
        ?array $foundationCapabilities = null,
    ): self {
        return self::boot($config, $releaseManifestPath, $adapter, false, $foundationCapabilities);
    }

    /**
     * Load using an externally trusted SHA-256 identity for the exact manifest
     * selected by Webrick. The digest must come from immutable deployment
     * metadata, never from the release directory being validated.
     *
     * @param array<string, mixed> $config
     * @param array<int|string, mixed>|null $foundationCapabilities Must match compile-time topology when explicit.
     */
    public static function loadPrevalidated(
        array $config,
        string $releaseManifestPath,
        string $trustedManifestSha256,
        ?RuntimeAdapterInterface $adapter = null,
        ?array $foundationCapabilities = null,
    ): self {
        self::assertTrustedManifest($releaseManifestPath, $trustedManifestSha256);

        return self::boot($config, $releaseManifestPath, $adapter, true, $foundationCapabilities);
    }

    private static function assertTrustedManifest(string $releaseManifestPath, string $trustedSha256): void
    {
        $trustedSha256 = strtolower(trim($trustedSha256));
        if (preg_match('/^[a-f0-9]{64}$/D', $trustedSha256) !== 1) {
            throw new \InvalidArgumentException('Trusted Foundation web release manifest SHA-256 is invalid.');
        }

        $runtimePath = WebrickReleaseCompiler::runtimeManifestPath($releaseManifestPath);
        $selectedPath = is_file($runtimePath) ? $runtimePath : $releaseManifestPath;
        if (!is_file($selectedPath)) {
            throw new \RuntimeException('Foundation web release manifest is missing.');
        }

        $actualSha256 = hash_file('sha256', $selectedPath);
        if (!is_string($actualSha256) || !hash_equals($trustedSha256, $actualSha256)) {
            throw new \RuntimeException('Foundation web release manifest trust identity mismatch.');
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int|string, mixed>|null $foundationCapabilities
     */
    private static function boot(
        array $config,
        string $releaseManifestPath,
        ?RuntimeAdapterInterface $adapter,
        bool $prevalidated,
        ?array $foundationCapabilities,
    ): self {
        $graph = new WebGraphFactory()->compose($config, $foundationCapabilities);
        $settings = new WebReleaseConfiguration($graph);
        $settings->assertArtifactSafeMiddleware();
        $releaseManifestPath = $settings->resolvePath($releaseManifestPath);
        $manifest = new ReleaseManifestLoader()->load($releaseManifestPath);

        $environment = $settings->environment();
        $configFingerprint = $settings->configFingerprint();
        if (($manifest['environment'] ?? null) !== $environment
            || !is_string($manifest['config_fingerprint'] ?? null)
            || !hash_equals($configFingerprint, $manifest['config_fingerprint'])
        ) {
            throw new \RuntimeException('Foundation web release configuration identity mismatch.');
        }

        $intermix = $manifest['intermix'] ?? null;
        $webrick = $manifest['webrick'] ?? null;
        if (!is_array($intermix) || !is_array($webrick)) {
            throw new \UnexpectedValueException('Foundation web release manifest is incomplete.');
        }

        $intermixPath = $settings->resolvePath(self::stringField($intermix, 'path'));
        $routerPath = $settings->resolvePath(self::stringField($webrick, 'path'));
        $intermixDigest = self::stringField($intermix, 'digest');
        $routerFingerprint = self::stringField($webrick, 'fingerprint');

        $routerLoader = new RouterArtifactLoader();
        $artifact = $prevalidated
            ? $routerLoader->loadPrevalidated(
                path: $routerPath,
                trustedArtifactFingerprint: $routerFingerprint,
                expectedEnvironment: $environment,
                expectedConfigFingerprint: $configFingerprint,
            )
            : $routerLoader->load(
                path: $routerPath,
                expectedEnvironment: $environment,
                expectedConfigFingerprint: $configFingerprint,
            );
        new WebProductionGraph()->prepareRuntime($graph->builder, $artifact);
        $container = $prevalidated
            ? $graph->builder->productionPrevalidated($intermixPath, $intermixDigest)
            : $graph->builder->production($intermixPath);
        $graph->application->container()->unset();

        $logger = $container->get(LoggerInterface::class);
        if (!$logger instanceof LoggerInterface) {
            throw new \LogicException('Compiled Foundation web runtime did not produce a PSR logger.');
        }
        $exceptionLogger = $container->get(HttpExceptionLogger::class);
        if (!$exceptionLogger instanceof LoggerInterface) {
            $exceptionLogger = $logger;
        }
        $errorHandler = new ErrorHandler(
            logger: $exceptionLogger,
            debug: $settings->debug(),
            requestIdHeader: 'X-Request-Id',
            responseRenderer: static function (Request $request, Throwable $exception) use ($container): ?Response {
                if (!ExceptionRenderer::supports($exception)) {
                    return null;
                }
                $renderer = $container->get(ExceptionRenderer::class);

                return $renderer instanceof ExceptionRenderer
                    ? $renderer->render($request, $exception)
                    : null;
            },
        );

        $kernel = $prevalidated
            ? CompiledRouterKernel::fromPrevalidatedArtifact(
                log: $logger,
                matcher: $settings->matcher(),
                container: $container,
                artifactPath: $routerPath,
                trustedArtifactFingerprint: $routerFingerprint,
                environment: $environment,
                configFingerprint: $configFingerprint,
                errorHandler: $errorHandler,
                urlBaseUri: $settings->urlBaseUri(),
                signKey: $settings->signKey(),
                signedDefaultTtl: $settings->signedDefaultTtl(),
                signedUrlConfig: $settings->signedUrlConfig(),
            )
            : CompiledRouterKernel::fromCompiledArtifact(
                log: $logger,
                matcher: $settings->matcher(),
                container: $container,
                artifactPath: $routerPath,
                environment: $environment,
                configFingerprint: $configFingerprint,
                errorHandler: $errorHandler,
                urlBaseUri: $settings->urlBaseUri(),
                signKey: $settings->signKey(),
                signedDefaultTtl: $settings->signedDefaultTtl(),
                signedUrlConfig: $settings->signedUrlConfig(),
            );
        $runtimeAdapter = $adapter ?? SapiRuntimeAdapter::current();
        $capabilities = $runtimeAdapter->capabilities();
        $server = new RuntimeServer($kernel, $runtimeAdapter);

        return new self($container, $kernel, $server, $capabilities);
    }

    /** @param array<array-key, mixed> $source */
    private static function stringField(array $source, string $field): string
    {
        $value = $source[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(sprintf('Foundation web release field "%s" is invalid.', $field));
        }

        return $value;
    }
}
