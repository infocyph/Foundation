<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;

final readonly class FoundationBuildContext
{
    public function __construct(
        public RuntimeMode $runtimeMode,
        public ?string $environment,
        public bool $lazyLoading,
        public bool $debugTracing,
        public TraceLevelEnum $debugTraceLevel,
    ) {}

    public static function fromConfig(ConfigRepository $config, RuntimeMode $runtimeMode): self
    {
        return new self(
            runtimeMode: $runtimeMode,
            environment: ValueNormalizer::nullableString($config->get('app.container.environment'))
                ?? ValueNormalizer::nullableString($config->get('app.env')),
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
