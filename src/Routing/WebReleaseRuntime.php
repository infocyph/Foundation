<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Http\Response\ExceptionRenderer;
use Infocyph\Foundation\Logging\HttpExceptionLogger;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\ReleaseManifestLoader;
use Infocyph\Webrick\Router\Build\RouterArtifactLoader;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Kernel\ErrorHandler;
use Infocyph\Webrick\Runtime\Http\RuntimeAdapterInterface;
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
    ) {}

    /** @param array<string, mixed> $config */
    public static function load(
        array $config,
        string $releaseManifestPath,
        ?RuntimeAdapterInterface $adapter = null,
    ): self {
        return self::boot($config, $releaseManifestPath, $adapter, false);
    }

    /**
     * Use only when the release manifest comes from trusted immutable deployment metadata.
     *
     * @param array<string, mixed> $config
     */
    public static function loadPrevalidated(
        array $config,
        string $releaseManifestPath,
        ?RuntimeAdapterInterface $adapter = null,
    ): self {
        return self::boot($config, $releaseManifestPath, $adapter, true);
    }

    /** @param array<string, mixed> $config */
    private static function boot(
        array $config,
        string $releaseManifestPath,
        ?RuntimeAdapterInterface $adapter,
        bool $prevalidated,
    ): self {
        $graph = new WebGraphFactory()->compose($config);
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
        $server = new RuntimeServer(
            $kernel,
            $adapter ?? SapiRuntimeAdapter::current(),
        );

        return new self($container, $kernel, $server);
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
