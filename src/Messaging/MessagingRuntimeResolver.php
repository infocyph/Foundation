<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Omnibus\Handler\HandlerMiddleware;
use Psr\Container\ContainerInterface;

/**
 * Explicit dynamic island for application-configured messaging service IDs.
 * The provider graph itself remains generated; only user-selected callables are
 * resolved from the finalized runtime container.
 */
final readonly class MessagingRuntimeResolver
{
    public function __construct(private ContainerInterface $container) {}

    public function callable(mixed $definition): callable
    {
        if (is_callable($definition)) {
            return $definition;
        }
        if (!is_string($definition) || $definition === '') {
            throw new \InvalidArgumentException('Messaging definitions must be callables or service class names.');
        }

        $service = $this->container->get($definition);
        if (!is_callable($service)) {
            throw new \InvalidArgumentException(sprintf('Messaging service "%s" is not callable.', $definition));
        }

        return $service;
    }

    /** @return list<HandlerMiddleware> */
    public function handlerMiddleware(mixed $configured, mixed $configuredJobs): array
    {
        if (!is_array($configured)) {
            throw new \InvalidArgumentException('messaging.handler_middleware must be an ordered middleware list.');
        }

        $middleware = [];
        foreach ($configured as $definition) {
            if ($definition instanceof HandlerMiddleware) {
                $middleware[] = $definition;
                continue;
            }
            if (!is_string($definition) || $definition === '') {
                throw new \InvalidArgumentException(
                    'Messaging handler middleware must be service class names or HandlerMiddleware instances.',
                );
            }

            $resolved = $this->container->get($definition);
            if (!$resolved instanceof HandlerMiddleware) {
                throw new \InvalidArgumentException(sprintf(
                    'Messaging handler middleware "%s" must implement %s.',
                    $definition,
                    HandlerMiddleware::class,
                ));
            }
            $middleware[] = $resolved;
        }

        $jobs = $this->jobMiddleware($configuredJobs);
        if ($jobs !== []) {
            $middleware[] = new JobMiddlewarePipeline($jobs);
        }

        return $middleware;
    }

    /** @return array<class-string, callable> */
    public function handlers(mixed $configured): array
    {
        $handlers = [];
        foreach ($this->map($configured) as $message => $handler) {
            if (!is_string($message) || (!class_exists($message) && !interface_exists($message))) {
                throw new \InvalidArgumentException('Messaging handler keys must be message class names.');
            }
            $handlers[$message] = fn(mixed ...$arguments): mixed => ($this->callable($handler))(...$arguments);
        }

        return $handlers;
    }

    /** @return list<JobMiddleware> */
    public function jobMiddleware(mixed $configured): array
    {
        if (!is_array($configured)) {
            throw new \InvalidArgumentException('messaging.job_middleware must be an ordered middleware list.');
        }

        $middleware = [];
        foreach ($configured as $definition) {
            if ($definition instanceof JobMiddleware) {
                $middleware[] = $definition;
                continue;
            }
            if (!is_string($definition) || $definition === '') {
                throw new \InvalidArgumentException(
                    'Messaging job middleware must be service class names or JobMiddleware instances.',
                );
            }

            $resolved = $this->container->get($definition);
            if (!$resolved instanceof JobMiddleware) {
                throw new \InvalidArgumentException(sprintf(
                    'Messaging job middleware "%s" must implement %s.',
                    $definition,
                    JobMiddleware::class,
                ));
            }
            $middleware[] = $resolved;
        }

        return $middleware;
    }

    /** @return array<class-string, list<callable>> */
    public function listeners(mixed $configured): array
    {
        $listeners = [];
        foreach ($this->map($configured) as $event => $definitions) {
            if (!is_string($event)
                || (!class_exists($event) && !interface_exists($event))
                || !is_array($definitions)
            ) {
                throw new \InvalidArgumentException('Messaging listeners must map event classes to listener lists.');
            }
            foreach ($definitions as $definition) {
                $listeners[$event][] = fn(mixed ...$arguments): mixed => ($this->callable($definition))(...$arguments);
            }
        }

        return $listeners;
    }

    /** @return array<string, callable(): object> */
    public function scheduledMessages(mixed $configured): array
    {
        $messages = [];
        foreach ($this->map($configured) as $name => $definition) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('Scheduled message keys must be non-empty strings.');
            }
            $messages[$name] = function () use ($definition): object {
                $message = ($this->callable($definition))();
                if (!is_object($message)) {
                    throw new \UnexpectedValueException('Scheduled message factories must return objects.');
                }

                return $message;
            };
        }

        return $messages;
    }

    /** @return array<array-key, mixed> */
    private function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
