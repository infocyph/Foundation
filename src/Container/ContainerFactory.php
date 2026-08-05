<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Container;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;

final class ContainerFactory
{
    public function create(ConfigRepository $config): Container
    {
        $container = new Container($this->alias($config) ?? $this->defaultAlias());

        $this->configure($container, $config);

        return $container;
    }

    private function alias(ConfigRepository $config): ?string
    {
        return ValueNormalizer::nullableString($config->get('app.container.alias'));
    }

    private function configure(Container $container, ConfigRepository $config): void
    {
        $options = $container->options();
        $environment = ValueNormalizer::nullableString($config->get('app.container.environment'))
            ?? ValueNormalizer::nullableString($config->get('app.env'));

        if ($environment !== null) {
            $options->setEnvironment($environment);
        }

        if (ValueNormalizer::bool($config->get('app.container.lazy_loading'), false)) {
            $options->enableLazyLoading();
        }

        $traceEnabled = ValueNormalizer::bool($config->get('app.container.debug_tracing.enabled'), false);
        if ($traceEnabled) {
            $options->enableDebugTracing(true, $this->traceLevel(
                ValueNormalizer::string($config->get('app.container.debug_tracing.level'), 'node'),
            ));
        }

    }

    private function defaultAlias(): string
    {
        return 'foundation.' . bin2hex(random_bytes(8));
    }

    private function traceLevel(string $value): TraceLevelEnum
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
