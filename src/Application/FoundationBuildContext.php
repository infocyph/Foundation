<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;

final readonly class FoundationBuildContext
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, bool> $capabilities
     */
    public function __construct(
        public RuntimeMode $runtimeMode,
        public ?string $environment,
        public array $config,
        public bool $compiledConfig,
        public array $capabilities,
        public bool $lazyLoading,
        public bool $debugTracing,
        public TraceLevelEnum $debugTraceLevel,
    ) {}

    /**
     * @param array<int|string, mixed> $capabilities
     */
    public static function fromConfig(
        ConfigRepository $config,
        RuntimeMode $runtimeMode,
        array $capabilities = [],
    ): self {
        return new self(
            runtimeMode: $runtimeMode,
            environment: ValueNormalizer::nullableString($config->get('app.container.environment'))
                ?? ValueNormalizer::nullableString($config->get('app.env')),
            config: $config->all(),
            compiledConfig: $config->isCompiled(),
            capabilities: self::normalizeCapabilities($capabilities),
            lazyLoading: ValueNormalizer::bool($config->get('app.container.lazy_loading'), true),
            debugTracing: ValueNormalizer::bool(
                $config->get('app.container.debug_tracing.enabled'),
                false,
            ),
            debugTraceLevel: self::traceLevel(
                ValueNormalizer::string($config->get('app.container.debug_tracing.level'), 'node'),
            ),
        );
    }

    public function hasCapability(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }

    /**
     * @param array<int|string, mixed> $capabilities
     * @return array<string, bool>
     */
    private static function normalizeCapabilities(array $capabilities): array
    {
        $normalized = [];
        foreach ($capabilities as $name => $enabled) {
            if (is_int($name)) {
                if (is_string($enabled) && $enabled !== '') {
                    $normalized[$enabled] = true;
                }

                continue;
            }

            if ($name !== '') {
                $normalized[$name] = ValueNormalizer::bool($enabled, false);
            }
        }

        ksort($normalized);

        return $normalized;
    }

    private static function traceLevel(string $value): TraceLevelEnum
    {
        return match (strtolower($value)) {
            'error' => TraceLevelEnum::Error,
            'info' => TraceLevelEnum::Info,
            'verbose' => TraceLevelEnum::Verbose,
            'warn', 'warning' => TraceLevelEnum::Warn,
            'off' => TraceLevelEnum::Off,
            default => TraceLevelEnum::Node,
        };
    }
}
