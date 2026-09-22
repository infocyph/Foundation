<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Runtime\ExecutionScope as FoundationExecutionScope;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
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
use Infocyph\Omnibus\Integration\DBLayer\AfterCommitDispatcher;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerWorkflowStore;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Scheduling\MessageFactoryMap;
use Infocyph\Omnibus\Scheduling\ScheduledMessageDispatcher;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;
use Infocyph\Omnibus\Workflow\WorkflowStore;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

final class MessagingServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $messaging = is_array($context->config['messaging'] ?? null) ? $context->config['messaging'] : [];
        $durable = $this->durableState($builder, $messaging);

        $this->registerRuntimeServices($builder);
        $this->registerDurableServices($builder, $durable['enabled']);
        $this->registerHandlerServices($builder, $messaging);
        $this->registerEventServices($builder, $messaging);
        $this->registerTransportServices($builder, $messaging, $durable['enabled']);
        $this->registerBusServices($builder, $durable['enabled']);
        $this->registerFailureStore($builder, $durable['enabled'], $durable['failure_driver']);
        $this->registerExecutionScope($builder);
        $this->registerConsumerServices($builder);
        $this->registerSchedulingServices($builder, $messaging);
        $builder->alias('foundation.messaging', MessageBus::class);
    }

    /**
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    private function arrayValue(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /** @param array<array-key, mixed> $messaging */
    private function assertDurableConfiguration(
        ContainerBuilder $builder,
        array $messaging,
        bool $enabled,
        string $failureDriver,
    ): void {
        $usesDatabase = $this->referencesDatabaseTransport($messaging);
        if ($usesDatabase && !$enabled) {
            throw new ConfigurationException(
                'Messaging references the database transport but messaging.durable.enabled is false.',
            );
        }
        if ($enabled && !$builder->definitions()->has(DBLayerFactory::class)) {
            throw new ConfigurationException(
                'messaging.durable.enabled requires the Foundation database capability.',
            );
        }
        if (!in_array($failureDriver, ['', 'database', 'memory'], true)) {
            throw new ConfigurationException(
                'messaging.durable.failure_store must be database or memory when configured.',
            );
        }
        if (
            $this->usesDatabaseConsumer($messaging)
            && $failureDriver === ''
            && !$builder->definitions()->has(FailureStore::class)
        ) {
            throw new ConfigurationException(
                'Durable database consumers/workers require an explicit messaging.durable.failure_store policy or FailureStore binding.',
            );
        }
    }

    /**
     * @param array<array-key, mixed> $messaging
     * @return array{enabled:bool,failure_driver:string}
     */
    private function durableState(ContainerBuilder $builder, array $messaging): array
    {
        $durable = $this->arrayValue($messaging, 'durable');
        $enabled = ValueNormalizer::bool($durable['enabled'] ?? null, false);
        $failureDriver = strtolower(ValueNormalizer::string($durable['failure_store'] ?? null, ''));

        $this->assertDurableConfiguration($builder, $messaging, $enabled, $failureDriver);

        return ['enabled' => $enabled, 'failure_driver' => $failureDriver];
    }

    /** @param array<array-key, mixed> $messaging */
    private function referencesDatabaseTransport(array $messaging): bool
    {
        $default = $this->arrayValue($messaging, 'default_route');
        if (($default['transport'] ?? null) === 'database') {
            return true;
        }
        foreach ($this->arrayValue($messaging, 'routes') as $route) {
            if (is_array($route) && ($route['transport'] ?? null) === 'database') {
                return true;
            }
        }

        return $this->usesDatabaseConsumer($messaging);
    }

    private function registerBusServices(ContainerBuilder $builder, bool $durableEnabled): void
    {
        if (!$builder->definitions()->has(MessageBus::class)) {
            $builder->singleton(MessageBus::class, FactoryDefinition::construct(
                MessageBus::class,
                [new ServiceReference(RouteMap::class), new ServiceReference(TransportRegistry::class)],
            ));
        }
        if ($durableEnabled && !$builder->definitions()->has(AfterCommitDispatcher::class)) {
            $builder->scoped(AfterCommitDispatcher::class, FactoryDefinition::staticFactory(
                MessagingGraphFactory::class,
                'afterCommit',
                [
                    new ServiceReference(OmnibusDurableFactory::class),
                    new ServiceReference(MessageBus::class),
                ],
            ));
        }
    }

    private function registerConsumerServices(ContainerBuilder $builder): void
    {
        $builder->singleton(ConsumerFactory::class, FactoryDefinition::construct(
            ConsumerFactory::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(TransportRegistry::class),
                new ServiceReference(HandlerInvoker::class),
                new ServiceReference(FailureStore::class),
                new ServiceReference(SystemClock::class),
                new ServiceReference(ExecutionScope::class),
            ],
        ));
        $builder->singleton(Consumer::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'consumer',
            [new ServiceReference(ConsumerFactory::class)],
        ));
        $builder->singleton(ConsumerTask::class, FactoryDefinition::construct(
            ConsumerTask::class,
            [new ServiceReference(Consumer::class)],
        ));
        $builder->singleton(OmnibusWorkerFactory::class, FactoryDefinition::construct(
            OmnibusWorkerFactory::class,
            [new ServiceReference(ConfigRepository::class), new ServiceReference(ContainerInterface::class)],
        ));
    }

    private function registerDurableServices(ContainerBuilder $builder, bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

        $builder->singleton(OmnibusDurableFactory::class, FactoryDefinition::construct(
            OmnibusDurableFactory::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(DBLayerFactory::class),
                new ServiceReference(MessagingRuntimeResolver::class),
                new ServiceReference(SystemClock::class),
            ],
        ));
        if (!$builder->definitions()->has(EnvelopeSerializer::class)) {
            $builder->singleton(JsonEnvelopeSerializer::class, FactoryDefinition::staticFactory(
                MessagingGraphFactory::class,
                'durableSerializer',
                [new ServiceReference(OmnibusDurableFactory::class)],
            ));
            $builder->alias(EnvelopeSerializer::class, JsonEnvelopeSerializer::class);
        }

        $builder->singleton(DBLayerTransport::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'durableTransport',
            [
                new ServiceReference(OmnibusDurableFactory::class),
                new ServiceReference(EnvelopeSerializer::class),
            ],
        ));
        $builder->singleton(DBLayerFailureStore::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'durableFailureStore',
            [
                new ServiceReference(OmnibusDurableFactory::class),
                new ServiceReference(EnvelopeSerializer::class),
            ],
        ));
        $builder->singleton(DBLayerWorkflowStore::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'durableWorkflowStore',
            [
                new ServiceReference(OmnibusDurableFactory::class),
                new ServiceReference(EnvelopeSerializer::class),
            ],
        ));
        if (!$builder->definitions()->has(WorkflowStore::class)) {
            $builder->alias(WorkflowStore::class, DBLayerWorkflowStore::class);
        }
        $builder->singleton(MessagingDatabaseSchema::class, FactoryDefinition::construct(
            MessagingDatabaseSchema::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(DBLayerFactory::class),
            ],
        ));
    }

    /** @param array<array-key, mixed> $messaging */
    private function registerEventServices(ContainerBuilder $builder, array $messaging): void
    {
        $builder->singleton(ListenerMap::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'listenerMap',
            [
                new ServiceReference(MessagingRuntimeResolver::class),
                $this->arrayValue($messaging, 'listeners'),
            ],
        ));
        if (!$builder->definitions()->has(ListenerProviderInterface::class)) {
            $builder->alias(ListenerProviderInterface::class, ListenerMap::class);
        }

        $builder->singleton(EventDispatcher::class, FactoryDefinition::construct(
            EventDispatcher::class,
            [new ServiceReference(ListenerProviderInterface::class), new ServiceReference(MessageBus::class)],
        ));
        if (!$builder->definitions()->has(EventDispatcherInterface::class)) {
            $builder->alias(EventDispatcherInterface::class, EventDispatcher::class);
        }
    }

    private function registerExecutionScope(ContainerBuilder $builder): void
    {
        $builder->singleton(InterMixExecutionScope::class, FactoryDefinition::construct(
            InterMixExecutionScope::class,
            [new ServiceReference(FoundationExecutionScope::class)],
        ));
        if (!$builder->definitions()->has(ExecutionScope::class)) {
            $builder->alias(ExecutionScope::class, InterMixExecutionScope::class);
        }
    }

    private function registerFailureStore(
        ContainerBuilder $builder,
        bool $durableEnabled,
        string $failureDriver,
    ): void {
        if ($builder->definitions()->has(FailureStore::class)) {
            return;
        }
        if ($durableEnabled && $failureDriver === 'database') {
            $builder->alias(FailureStore::class, DBLayerFailureStore::class);

            return;
        }

        $builder->singleton(FailureStore::class, FactoryDefinition::construct(InMemoryFailureStore::class));
    }

    /** @param array<array-key, mixed> $messaging */
    private function registerHandlerServices(ContainerBuilder $builder, array $messaging): void
    {
        $handlers = $this->arrayValue($messaging, 'handlers');
        $handlerMiddleware = $this->arrayValue($messaging, 'handler_middleware');
        $jobMiddleware = $this->arrayValue($messaging, 'job_middleware');

        $builder->singleton(HandlerMap::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'handlerMap',
            [new ServiceReference(MessagingRuntimeResolver::class), $handlers],
        ));
        if (!$builder->definitions()->has(HandlerInvoker::class)) {
            $builder->singleton(HandlerInvoker::class, FactoryDefinition::staticFactory(
                MessagingGraphFactory::class,
                'handlerInvoker',
                [
                    new ServiceReference(MessagingRuntimeResolver::class),
                    new ServiceReference(HandlerMap::class),
                    $handlerMiddleware,
                    $jobMiddleware,
                ],
            ));
        }
    }

    private function registerRuntimeServices(ContainerBuilder $builder): void
    {
        $builder->singleton(MessagingRuntimeResolver::class, FactoryDefinition::construct(
            MessagingRuntimeResolver::class,
            [new ServiceReference(ContainerInterface::class)],
        ));
        $builder->singleton(SystemClock::class, FactoryDefinition::construct(SystemClock::class));
    }

    /** @param array<array-key, mixed> $messaging */
    private function registerSchedulingServices(ContainerBuilder $builder, array $messaging): void
    {
        $builder->singleton(MessageFactoryMap::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'messageFactoryMap',
            [
                new ServiceReference(MessagingRuntimeResolver::class),
                $this->arrayValue($messaging, 'scheduled_messages'),
            ],
        ));
        $builder->singleton(ScheduledMessageDispatcher::class, FactoryDefinition::construct(
            ScheduledMessageDispatcher::class,
            [new ServiceReference(MessageFactoryMap::class), new ServiceReference(MessageBus::class)],
        ));
    }

    /** @param array<array-key, mixed> $messaging */
    private function registerTransportServices(
        ContainerBuilder $builder,
        array $messaging,
        bool $durableEnabled,
    ): void {
        $builder->singleton(RouteMap::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'routeMap',
            [
                $this->arrayValue($messaging, 'routes'),
                $this->arrayValue($messaging, 'default_route'),
            ],
        ));
        $builder->singleton(InMemoryTransport::class, FactoryDefinition::construct(
            InMemoryTransport::class,
            [new ServiceReference(SystemClock::class)],
        ));
        $builder->singleton(SyncTransport::class, FactoryDefinition::construct(
            SyncTransport::class,
            [new ServiceReference(HandlerInvoker::class)],
        ));
        if (!$builder->definitions()->has(TransportRegistry::class)) {
            $builder->singleton(TransportRegistry::class, FactoryDefinition::staticFactory(
                MessagingGraphFactory::class,
                'transports',
                [
                    new ServiceReference(SyncTransport::class),
                    new ServiceReference(InMemoryTransport::class),
                    $durableEnabled ? new ServiceReference(DBLayerTransport::class) : null,
                ],
            ));
        }
    }

    /** @param array<array-key, mixed> $messaging */
    private function usesDatabaseConsumer(array $messaging): bool
    {
        $consumer = $this->arrayValue($messaging, 'consumer');
        if (($consumer['transport'] ?? null) === 'database') {
            return true;
        }

        return array_any(
            $this->arrayValue($messaging, 'workers'),
            fn($worker) => is_array($worker) && ($worker['transport'] ?? null) === 'database',
        );
    }
}
