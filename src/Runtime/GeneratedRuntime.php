<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProviderInterface;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\ProductionContainer;

/** One generated CLI, worker, or scheduler runtime reused for its process lifetime. */
final readonly class GeneratedRuntime
{
    /** @param array<string,mixed> $metadata */
    private function __construct(
        public RuntimeMode $runtimeMode,
        public ProductionContainer $container,
        public Application $application,
        public array $metadata,
    ) {}

    /**
     * @param array<string,mixed> $config
     * @param array<int|string,mixed> $capabilities Must match the compile-time topology.
     */
    public static function load(
        array $config,
        RuntimeMode $runtime,
        string $artifactPath,
        array $capabilities = [],
    ): self {
        return self::bootValidated($config, $runtime, $artifactPath, $capabilities);
    }

    /**
     * Trusted loading is valid only when both identities originate outside the
     * writable release directory (normally a trusted Foundation generation manifest).
     * This path deliberately does not rebuild the Foundation source graph.
     *
     * @param array<string,mixed> $config Used only to resolve a relative artifact path.
     * @param array<int|string,mixed> $capabilities
     */
    public static function loadPrevalidated(
        array $config,
        RuntimeMode $runtime,
        string $artifactPath,
        string $trustedMetadataSha256,
        string $trustedIntermixDigest,
        array $capabilities = [],
    ): self {
        if ($runtime === RuntimeMode::Web) {
            throw new \InvalidArgumentException('Web production runtime must use the coordinated Webrick release loader.');
        }

        $trustedMetadataSha256 = strtolower(trim($trustedMetadataSha256));
        $trustedIntermixDigest = strtolower(trim($trustedIntermixDigest));
        if (preg_match('/^[a-f0-9]{64}$/D', $trustedMetadataSha256) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $trustedIntermixDigest) !== 1
        ) {
            throw new \InvalidArgumentException('Trusted generated-runtime identity is invalid.');
        }

        $artifactPath = self::resolvePrevalidatedPath($config, $artifactPath);
        self::assertTrustedMetadata($artifactPath, $trustedMetadataSha256);
        $metadata = GeneratedRuntimeMetadata::read($artifactPath);
        GeneratedRuntimeMetadata::assertPrevalidatedIdentity(
            $artifactPath,
            $metadata,
            $runtime,
            $capabilities,
            $trustedIntermixDigest,
        );

        return self::finishBoot(
            $runtime,
            self::loadArtifact($artifactPath, $trustedIntermixDigest),
            self::providerRegistry($metadata),
            new Bootstrapper(),
            $metadata,
        );
    }

    private static function assertTrustedMetadata(string $artifactPath, string $trustedSha256): void
    {
        $metadataPath = GeneratedRuntimeMetadata::path($artifactPath);
        if (!is_file($metadataPath)) {
            throw new \RuntimeException('Foundation generated-runtime metadata is missing.');
        }
        $actualSha256 = hash_file('sha256', $metadataPath);
        if (!is_string($actualSha256) || !hash_equals($trustedSha256, $actualSha256)) {
            throw new \RuntimeException('Foundation generated-runtime metadata trust identity mismatch.');
        }
    }

    /**
     * @param array<string,mixed> $config
     * @param array<int|string,mixed> $capabilities
     */
    private static function bootValidated(
        array $config,
        RuntimeMode $runtime,
        string $artifactPath,
        array $capabilities,
    ): self {
        if ($runtime === RuntimeMode::Web) {
            throw new \InvalidArgumentException('Web production runtime must use the coordinated Webrick release loader.');
        }

        $graph = new NonWebGraphFactory()->compose($config, $runtime, $capabilities);

        try {
            new NonWebProductionGraph()->prepare($graph->builder);
            $artifactPath = GeneratedRuntimeMetadata::resolvePath($graph->application, $artifactPath);
            $metadata = GeneratedRuntimeMetadata::read($artifactPath);
            GeneratedRuntimeMetadata::assertMatches($artifactPath, $metadata, $graph);
            $container = $graph->builder->production($artifactPath);
        } finally {
            $graph->application->container()->unset();
        }

        return self::finishBoot(
            $runtime,
            $container,
            $graph->providers,
            $graph->bootstrapper,
            $metadata,
        );
    }

    /** @param array<string,mixed> $metadata */
    private static function finishBoot(
        RuntimeMode $runtime,
        ProductionContainer $container,
        ServiceRegistry $providers,
        Bootstrapper $bootstrapper,
        array $metadata,
    ): self {
        $runtimeConfig = $container->get(ConfigRepository::class);
        if (!$runtimeConfig instanceof ConfigRepository) {
            throw new \LogicException('Generated Foundation runtime did not produce a ConfigRepository.');
        }

        $application = new Application(
            config: $runtimeConfig,
            container: $container,
            providers: $providers,
            bootstrapper: $bootstrapper,
            runtimeMode: $runtime,
            bindDevelopmentCore: false,
        );
        $application->boot();

        return new self($runtime, $container, $application, $metadata);
    }

    private static function loadArtifact(string $artifactPath, string $trustedIntermixDigest): ProductionContainer
    {
        return new StaticRuntimeGenerator()->loadPrevalidated(
            $artifactPath,
            $trustedIntermixDigest,
        );
    }

    /** @param array<string,mixed> $metadata */
    private static function providerRegistry(array $metadata): ServiceRegistry
    {
        $classes = $metadata['provider_boot_order'] ?? null;
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new \UnexpectedValueException(
                'Foundation generated runtime metadata has no deterministic provider boot order.',
            );
        }

        $registry = new ServiceRegistry();
        foreach ($classes as $provider) {
            if (!is_string($provider)
                || $provider === ''
                || !class_exists($provider)
                || !is_a($provider, ServiceProviderInterface::class, true)
            ) {
                throw new \UnexpectedValueException(
                    'Foundation generated runtime metadata contains an invalid service provider.',
                );
            }

            $instance = new $provider();
            if (!$instance instanceof ServiceProviderInterface) {
                throw new \UnexpectedValueException(
                    'Foundation generated runtime provider could not be instantiated.',
                );
            }
            $registry->add($instance);
        }

        return $registry;
    }

    /** @param array<string,mixed> $config */
    private static function resolvePrevalidatedPath(array $config, string $path): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Generated runtime artifact path must not be empty.');
        }
        if (preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1) {
            return $path;
        }

        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $basePath = $app['base_path'] ?? $config['base_path'] ?? null;
        if (!is_string($basePath) || $basePath === '') {
            $basePath = getcwd() ?: dirname(__DIR__, 2);
        }

        return rtrim($basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ltrim($path, DIRECTORY_SEPARATOR);
    }
}
