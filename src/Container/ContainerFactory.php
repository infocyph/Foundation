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

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function alias(ConfigRepository $config): ?string
    {
        $legacy = ValueNormalizer::nullableString($config->get('app.container_alias'));
        $configured = ValueNormalizer::nullableString($config->get('app.container.alias'));

        return $configured ?? $legacy;
    }

    private function compiledPath(ConfigRepository $config): ?string
    {
        $configured = ValueNormalizer::nullableString($config->get('app.container.compiled'));
        if ($configured === null) {
            return null;
        }

        if ($this->absolute($configured)) {
            return $configured;
        }

        $basePath = ValueNormalizer::string($config->get('app.base_path'), getcwd() ?: '.');

        return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($configured, DIRECTORY_SEPARATOR);
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

        $compiledPath = $this->compiledPath($config);
        if ($compiledPath !== null && is_file($compiledPath)) {
            $container->useCompiled($compiledPath);
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
