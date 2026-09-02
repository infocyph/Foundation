<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Logging;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class LoggingServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $logging = is_array($context->config['logging'] ?? null) ? $context->config['logging'] : [];
        $exceptions = is_array($logging['exceptions'] ?? null) ? $logging['exceptions'] : [];

        if (!$builder->definitions()->has(LoggerInterface::class)) {
            $driver = ValueNormalizer::string($logging['driver'] ?? null, 'null');
            $definition = $driver === 'null'
                ? FactoryDefinition::construct(NullLogger::class)
                : FactoryDefinition::construct(JsonLogger::class, [
                    $driver,
                    ValueNormalizer::string($logging['level'] ?? null, 'warning'),
                    $this->logPath($context, $logging['path'] ?? null),
                    ValueNormalizer::stringList($logging['redact'] ?? []),
                    ValueNormalizer::bool($exceptions['include_message'] ?? null, false),
                    ValueNormalizer::bool($exceptions['include_trace'] ?? null, false),
                ]);
            $builder->singleton(LoggerInterface::class, $definition);
        }

        $builder->singleton(
            ExceptionReporter::class,
            FactoryDefinition::construct(ExceptionReporter::class, [
                new ServiceReference(LoggerInterface::class),
                ValueNormalizer::bool($exceptions['include_message'] ?? null, false),
                $this->ignoredExceptions($exceptions['ignore'] ?? []),
                ValueNormalizer::float($exceptions['sample_rate'] ?? null, 1.0),
                ValueNormalizer::int($exceptions['throttle_seconds'] ?? null, 0),
                ValueNormalizer::int($exceptions['throttle_limit'] ?? null, 1),
            ]),
        );
        $builder->singleton(
            HttpExceptionLogger::class,
            FactoryDefinition::construct(HttpExceptionLogger::class, [
                new ServiceReference(ExceptionReporter::class),
            ]),
        );
        $builder->alias('foundation.logging', LoggerInterface::class);
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /** @return list<class-string<\Throwable>> */
    private function ignoredExceptions(mixed $configured): array
    {
        $classes = [];
        foreach (ValueNormalizer::stringList($configured) as $class) {
            if (is_a($class, \Throwable::class, true)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function logPath(FoundationBuildContext $context, mixed $configured): string
    {
        $path = ValueNormalizer::nullableString($configured);
        if ($path !== null && $this->absolute($path)) {
            return $path;
        }

        $app = is_array($context->config['app'] ?? null) ? $context->config['app'] : [];
        $paths = is_array($context->config['paths'] ?? null) ? $context->config['paths'] : [];
        $base = $app['base_path'] ?? null;
        $base = is_string($base) && $base !== ''
            ? rtrim($base, DIRECTORY_SEPARATOR)
            : (getcwd() ?: dirname(__DIR__, 2));

        if ($path !== null) {
            return $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }

        $logs = $paths['logs'] ?? 'logs';
        $logs = is_string($logs) && $logs !== '' ? $logs : 'logs';
        $logs = $this->absolute($logs)
            ? rtrim($logs, DIRECTORY_SEPARATOR)
            : $base . DIRECTORY_SEPARATOR . trim($logs, DIRECTORY_SEPARATOR);

        return $logs . DIRECTORY_SEPARATOR . 'foundation.log';
    }
}
