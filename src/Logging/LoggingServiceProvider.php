<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Logging;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class LoggingServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        if (!$this->hasExplicitBinding($container, LoggerInterface::class)) {
            $this->bindFactory(
                $container,
                LoggerInterface::class,
                fn(): LoggerInterface => $this->logger($app),
                LifetimeEnum::Singleton,
            );
        }
        $this->bindFactory($container, ExceptionReporter::class, fn() => new ExceptionReporter(
            logger: $app->make(LoggerInterface::class),
            includeMessage: ValueNormalizer::bool(
                $app->config()->get('logging.exceptions.include_message', false),
                false,
            ),
            ignoredExceptions: $this->ignoredExceptions($app),
            sampleRate: ValueNormalizer::float(
                $app->config()->get('logging.exceptions.sample_rate', 1.0),
                1.0,
            ),
            throttleSeconds: ValueNormalizer::int(
                $app->config()->get('logging.exceptions.throttle_seconds', 0),
                0,
            ),
            throttleLimit: ValueNormalizer::int(
                $app->config()->get('logging.exceptions.throttle_limit', 1),
                1,
            ),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, HttpExceptionLogger::class, fn() => new HttpExceptionLogger(
            $app->make(ExceptionReporter::class),
        ), LifetimeEnum::Singleton);
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /**
     * @return list<class-string<\Throwable>>
     */
    private function ignoredExceptions(Application $app): array
    {
        $classes = [];
        foreach (ValueNormalizer::stringList($app->config()->get('logging.exceptions.ignore', [])) as $class) {
            if (is_a($class, \Throwable::class, true)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function logger(Application $app): LoggerInterface
    {
        $driver = ValueNormalizer::string($app->config()->get('logging.driver', 'null'), 'null');
        if ($driver === 'null') {
            return new NullLogger();
        }

        $path = ValueNormalizer::nullableString($app->config()->get('logging.path'));
        $path = match (true) {
            $path === null => $app->logsPath('foundation.log'),
            $this->absolute($path) => $path,
            default => $app->basePath($path),
        };

        return new JsonLogger(
            driver: $driver,
            minimumLevel: ValueNormalizer::string(
                $app->config()->get('logging.level', 'warning'),
                'warning',
            ),
            path: $path,
            redactedKeys: ValueNormalizer::stringList(
                $app->config()->get('logging.redact', []),
            ),
            includeExceptionMessage: ValueNormalizer::bool(
                $app->config()->get('logging.exceptions.include_message', false),
                false,
            ),
            includeExceptionTrace: ValueNormalizer::bool(
                $app->config()->get('logging.exceptions.include_trace', false),
                false,
            ),
        );
    }
}
