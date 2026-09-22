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
        $handlers = $this->arrayValue($messaging, 'handlers');
        $handlerMiddleware = $this->arrayValue($messaging, 'handler_middleware');
        $jobMiddleware = $this->arrayValue($messaging, 'job_middleware');
        $listeners = $this->arrayValue($messaging, 'listeners');
        $routes = $this->arrayValue($messaging, 'routes');
        $defaultRoute = $this->arrayValue($messaging, 'default_route');
        $scheduledMessages = $this->arrayValue($messaging, 'scheduled_messages');
        $durable = $this->arrayValue($messaging, 'durable');
        $durableEnabled = ValueNormalizer::bool($durable['enabled'] ?? null, false);
        $failureDriver = strtolower(ValueNormalizer::string($durable['failure_store'] ?? null, ''));

        $this->assertDurableConfiguration($builder, $messaging, $durableEnabled, $failureDriver);

        $builder->singleton(MessagingRuntimeResolver::class, FactoryDefinition::construct(
            MessagingRuntimeResolver::class,
            [new ServiceReference(ContainerInterface::class)],
        ));
        $builder->singleton(SystemClock::class, FactoryDefinition::construct(SystemClock::class));

        if ($durableEnabled) {
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
                    OmnibusDurableFactory::class,
                    'serializer',
                    [],
                ));
                $builder->alias(EnvelopeSerializer::class, JsonEnvelopeSerializer::class);
            }
            $builder->singleton(DBLayerTransport::class, FactoryDefinition::staticFactory(
                OmnibusDurableFactory::class,
                'transport',
                [new ServiceReference(EnvelopeSerializer::class)],
            ));
            $builder->singleton(DBLayerFailureStore::class, FactoryDefinition::staticFactory(
                OmnibusDurableFactory::class,
                'failureStore',
                [new ServiceReference(EnvelopeSerializer::class)],
            ));
            $builder->singleton(DBLayerWorkflowStore::class, FactoryDefinition::staticFactory(
                OmnibusDurableFactory::class,
                'workflowStore',
                [new ServiceReference(EnvelopeSerializer::class)],
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
        $builder->singleton(ListenerMap::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'listenerMap',
            [new ServiceReference(MessagingRuntimeResolver::class), $listeners],
        ));
        if (!$builder->definitions()->has(ListenerProviderInterface::class)) {
            $builder->alias(ListenerProviderInterface::class, ListenerMap::class);
        }

        $builder->singleton(RouteMap::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'routeMap',
            [$routes, $defaultRoute],
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
        if (!$builder->definitions()->has(MessageBus::class)) {
            $builder->singleton(MessageBus::class, FactoryDefinition::construct(
                MessageBus::class,
                [new ServiceReference(RouteMap::class), new ServiceReference(TransportRegistry::class)],
            ));
        }
        if ($durableEnabled && !$builder->definitions()->has(AfterCommitDispatcher::class)) {
            $builder->scoped(AfterCommitDispatcher::class, FactoryDefinition::staticFactory(
                OmnibusDurableFactory::class,
                'afterCommit',
                [new ServiceReference(MessageBus::class)],
            ));
        }

        $builder->singleton(EventDispatcher::class, FactoryDefinition::construct(
            EventDispatcher::class,
            [new ServiceReference(ListenerProviderInterface::class), new ServiceReference(MessageBus::class)],
        ));
        if (!$builder->definitions()->has(EventDispatcherInterface::class)) {
            $builder->alias(EventDispatcherInterface::class, EventDispatcher::class);
        }
        if (!$builder->definitions()->has(FailureStore::class)) {
            if ($durableEnabled && $failureDriver === 'database') {
                $builder->alias(FailureStore::class, DBLayerFailureStore::class);
            } else {
                $builder->singleton(FailureStore::class, FactoryDefinition::construct(InMemoryFailureStore::class));
            }
        }

        $builder->singleton(InterMixExecutionScope::class, FactoryDefinition::construct(
            InterMixExecutionScope::class,
            [new ServiceReference(FoundationExecutionScope::class)],
        ));
        if (!$builder->definitions()->has(ExecutionScope::class)) {
            $builder->alias(ExecutionScope::class, InterMixExecutionScope::class);
        }

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

        $builder->singleton(MessageFactoryMap::class, FactoryDefinition::staticFactory(
            MessagingGraphFactory::class,
            'messageFactoryMap',
            [new ServiceReference(MessagingRuntimeResolver::class), $scheduledMessages],
        ));
        $builder->singleton(ScheduledMessageDispatcher::class, FactoryDefinition::construct(
            ScheduledMessageDispatcher::class,
            [new ServiceReference(MessageFactoryMap::class), new ServiceReference(MessageBus::class)],
        ));
        $builder->alias('foundation.messaging', MessageBus::class);
    }

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
        if ($this->usesDatabaseConsumer($messaging) && $failureDriver === '') {
            throw new ConfigurationException(
                'Durable database consumers/workers require an explicit messaging.durable.failure_store policy.',
            );
        }
    }

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

    private function usesDatabaseConsumer(array $messaging): bool
    {
        $consumer = $this->arrayValue($messaging, 'consumer');
        if (($consumer['transport'] ?? null) === 'database') {
            return true;
        }
        foreach ($this->arrayValue($messaging, 'workers') as $worker) {
            if (is_array($worker) && ($worker['transport'] ?? null) === 'database') {
                return true;
            }
        }

        return false;
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
}
