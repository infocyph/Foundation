<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
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
        return self::boot($config, $runtime, $artifactPath, $capabilities, null, null);
    }

    /**
     * Trusted loading is valid only when both identities originate outside the
     * writable release directory (normally a trusted Foundation generation manifest).
     *
     * @param array<string,mixed> $config
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
        $trustedMetadataSha256 = strtolower(trim($trustedMetadataSha256));
        $trustedIntermixDigest = strtolower(trim($trustedIntermixDigest));
        if (preg_match('/^[a-f0-9]{64}$/D', $trustedMetadataSha256) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $trustedIntermixDigest) !== 1
        ) {
            throw new \InvalidArgumentException('Trusted generated-runtime identity is invalid.');
        }

        return self::boot(
            $config,
            $runtime,
            $artifactPath,
            $capabilities,
            $trustedMetadataSha256,
            $trustedIntermixDigest,
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
    private static function boot(
        array $config,
        RuntimeMode $runtime,
        string $artifactPath,
        array $capabilities,
        ?string $trustedMetadataSha256,
        ?string $trustedIntermixDigest,
    ): self {
        if ($runtime === RuntimeMode::Web) {
            throw new \InvalidArgumentException('Web production runtime must use the coordinated Webrick release loader.');
        }

        $graph = new NonWebGraphFactory()->compose($config, $runtime, $capabilities);

        try {
            new NonWebProductionGraph()->prepare($graph->builder);
            $artifactPath = GeneratedRuntimeMetadata::resolvePath($graph->application, $artifactPath);
            if ($trustedMetadataSha256 !== null) {
                self::assertTrustedMetadata($artifactPath, $trustedMetadataSha256);
            }
            $metadata = GeneratedRuntimeMetadata::read($artifactPath);
            GeneratedRuntimeMetadata::assertMatches($artifactPath, $metadata, $graph);

            if ($trustedIntermixDigest !== null) {
                $metadataDigest = $metadata['intermix_digest'] ?? null;
                if (!is_string($metadataDigest) || !hash_equals($trustedIntermixDigest, $metadataDigest)) {
                    throw new \RuntimeException('Trusted generated-runtime digest does not match Foundation metadata.');
                }
                $container = $graph->builder->productionPrevalidated($artifactPath, $trustedIntermixDigest);
            } else {
                $container = $graph->builder->production($artifactPath);
            }
        } finally {
            $graph->application->container()->unset();
        }

        $runtimeConfig = $container->get(ConfigRepository::class);
        if (!$runtimeConfig instanceof ConfigRepository) {
            throw new \LogicException('Generated Foundation runtime did not produce a ConfigRepository.');
        }

        $application = new Application(
            config: $runtimeConfig,
            container: $container,
            providers: $graph->providers,
            bootstrapper: $graph->bootstrapper,
            runtimeMode: $runtime,
            bindDevelopmentCore: false,
        );
        $application->boot();

        return new self($runtime, $container, $application, $metadata);
    }
}
