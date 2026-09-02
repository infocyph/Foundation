<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Consumer\Command\ConsumerTask;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Consumer\ExecutionScope;
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Failure\FailureStore;
use Infocyph\Omnibus\Failure\InMemoryFailureStore;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Handler\HandlerMiddleware;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Scheduling\MessageFactoryMap;
use Infocyph\Omnibus\Scheduling\ScheduledMessageDispatcher;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

final class MessagingServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $app = $this->application($builder, $context);
        $container = $builder->development();

        // Handler/listener/scheduled-message callables are intentional dynamic islands.
        $this->bindFactory($container, SystemClock::class, static fn() => new SystemClock(), LifetimeEnum::Singleton);
        $this->bindFactory($container, HandlerMap::class, fn() => new HandlerMap(
            $this->handlers($app, $context->config['messaging']['handlers'] ?? []),
        ), LifetimeEnum::Singleton);
        if (!$this->hasExplicitBinding($container, HandlerInvoker::class)) {
            $this->bindFactory($container, HandlerInvoker::class, fn() => new HandlerInvoker(
                $app->make(HandlerMap::class),
                $this->handlerMiddleware(
                    $app,
                    $context->config['messaging']['handler_middleware'] ?? [],
                    $context->config['messaging']['job_middleware'] ?? [],
                ),
            ), LifetimeEnum::Singleton);
        }
        $this->bindFactory($container, ListenerMap::class, fn() => new ListenerMap(
            $this->listeners($app, $context->config['messaging']['listeners'] ?? []),
        ), LifetimeEnum::Singleton);
        if (!$this->hasExplicitBinding($container, ListenerProviderInterface::class)) {
            $container->alias(ListenerProviderInterface::class, ListenerMap::class, LifetimeEnum::Singleton);
        }

        $this->bindFactory($container, RouteMap::class, fn() => new RouteMap(
            $this->routes($context->config['messaging']['routes'] ?? []),
            $this->route($context->config['messaging']['default_route'] ?? []),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, InMemoryTransport::class, fn() => new InMemoryTransport(
            $app->make(SystemClock::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, SyncTransport::class, fn() => new SyncTransport(
            $app->make(HandlerInvoker::class),
        ), LifetimeEnum::Singleton);
        if (!$this->hasExplicitBinding($container, TransportRegistry::class)) {
            $this->bindFactory($container, TransportRegistry::class, fn() => new TransportRegistry([
                'sync' => $app->make(SyncTransport::class),
                'memory' => $app->make(InMemoryTransport::class),
            ]), LifetimeEnum::Singleton);
        }
        if (!$this->hasExplicitBinding($container, MessageBus::class)) {
            $this->bindFactory($container, MessageBus::class, fn() => new MessageBus(
                $app->make(RouteMap::class),
                $app->make(TransportRegistry::class),
            ), LifetimeEnum::Singleton);
        }

        $this->bindFactory($container, EventDispatcher::class, fn() => new EventDispatcher(
            $app->make(ListenerProviderInterface::class),
            $app->make(MessageBus::class),
        ), LifetimeEnum::Singleton);
        if (!$this->hasExplicitBinding($container, EventDispatcherInterface::class)) {
            $container->alias(EventDispatcherInterface::class, EventDispatcher::class, LifetimeEnum::Singleton);
        }
        if (!$this->hasExplicitBinding($container, FailureStore::class)) {
            $container->bind(FailureStore::class, new InMemoryFailureStore(), LifetimeEnum::Singleton);
        }

        // Scope ownership is deliberately deferred to Phase 4.
        $this->bindFactory($container, InterMixExecutionScope::class, fn() => new InterMixExecutionScope($app), LifetimeEnum::Singleton);
        if (!$this->hasExplicitBinding($container, ExecutionScope::class)) {
            $container->alias(ExecutionScope::class, InterMixExecutionScope::class, LifetimeEnum::Singleton);
        }

        $this->bindFactory($container, ConsumerFactory::class, fn() => new ConsumerFactory(
            config: $app->config(),
            transports: $app->make(TransportRegistry::class),
            invoker: $app->make(HandlerInvoker::class),
            failures: $app->make(FailureStore::class),
            clock: $app->make(SystemClock::class),
            scope: $app->make(ExecutionScope::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, Consumer::class, fn() => $app->make(ConsumerFactory::class)->make(), LifetimeEnum::Singleton);
        $this->bindFactory($container, ConsumerTask::class, fn() => new ConsumerTask($app->make(Consumer::class)), LifetimeEnum::Singleton);
        $this->bindFactory($container, OmnibusWorkerFactory::class, fn() => new OmnibusWorkerFactory(
            $app->config(),
            fn() => $app->make(ConsumerFactory::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, MessageFactoryMap::class, fn() => new MessageFactoryMap(
            $this->scheduledMessages($app, $context->config['messaging']['scheduled_messages'] ?? []),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, ScheduledMessageDispatcher::class, fn() => new ScheduledMessageDispatcher(
            $app->make(MessageFactoryMap::class),
            $app->make(MessageBus::class),
        ), LifetimeEnum::Singleton);
        $container->alias('foundation.messaging', MessageBus::class, LifetimeEnum::Singleton);
    }

    private function callable(Application $app, mixed $definition): callable
    {
        if (is_callable($definition)) {
            return $definition;
        }
        if (!is_string($definition) || $definition === '') {
            throw new \InvalidArgumentException('Messaging definitions must be callables or service class names.');
        }

        $service = $app->container()->make($definition);
        if (!is_callable($service)) {
            throw new \InvalidArgumentException(sprintf('Messaging service "%s" is not callable.', $definition));
        }

        return $service;
    }

    private function float(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    /** @return list<HandlerMiddleware> */
    private function handlerMiddleware(Application $app, mixed $configured, mixed $configuredJobs): array
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

            $resolved = $app->container()->make($definition);
            if (!$resolved instanceof HandlerMiddleware) {
                throw new \InvalidArgumentException(sprintf(
                    'Messaging handler middleware "%s" must implement %s.',
                    $definition,
                    HandlerMiddleware::class,
                ));
            }
            $middleware[] = $resolved;
        }

        $jobs = $this->jobMiddleware($app, $configuredJobs);
        if ($jobs !== []) {
            $middleware[] = new JobMiddlewarePipeline($jobs);
        }

        return $middleware;
    }

    /** @return array<class-string, callable> */
    private function handlers(Application $app, mixed $configured): array
    {
        $handlers = [];
        foreach ($this->map($configured) as $message => $handler) {
            if (!is_string($message) || (!class_exists($message) && !interface_exists($message))) {
                throw new \InvalidArgumentException('Messaging handler keys must be message class names.');
            }
            $handlers[$message] = fn(mixed ...$arguments): mixed => ($this->callable($app, $handler))(...$arguments);
        }

        return $handlers;
    }

    /** @return list<JobMiddleware> */
    private function jobMiddleware(Application $app, mixed $configured): array
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

            $resolved = $app->container()->make($definition);
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
    private function listeners(Application $app, mixed $configured): array
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
                $listeners[$event][] = fn(mixed ...$arguments): mixed => ($this->callable($app, $definition))(...$arguments);
            }
        }

        return $listeners;
    }

    /** @return array<array-key, mixed> */
    private function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function route(mixed $definition): Route
    {
        $definition = is_array($definition) ? $definition : [];

        return new Route(
            transport: ValueNormalizer::string($definition['transport'] ?? null, 'sync'),
            queue: ValueNormalizer::string($definition['queue'] ?? null, 'default'),
            delaySeconds: $this->float($definition['delay_seconds'] ?? null, 0.0),
        );
    }

    /** @return array<class-string, Route> */
    private function routes(mixed $configured): array
    {
        $routes = [];
        foreach ($this->map($configured) as $message => $definition) {
            if (!is_string($message) || (!class_exists($message) && !interface_exists($message))) {
                throw new \InvalidArgumentException('Messaging route keys must be message class names.');
            }
            $routes[$message] = $this->route($definition);
        }

        return $routes;
    }

    /** @return array<string, callable(): object> */
    private function scheduledMessages(Application $app, mixed $configured): array
    {
        $messages = [];
        foreach ($this->map($configured) as $name => $definition) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('Scheduled message keys must be non-empty strings.');
            }
            $messages[$name] = function () use ($app, $definition): object {
                $message = ($this->callable($app, $definition))();
                if (!is_object($message)) {
                    throw new \UnexpectedValueException('Scheduled message factories must return objects.');
                }

                return $message;
            };
        }

        return $messages;
    }
}
