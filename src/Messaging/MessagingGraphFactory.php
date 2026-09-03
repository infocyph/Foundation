<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Omnibus\Consumer\Consumer;
use Infocyph\Omnibus\Event\ListenerMap;
use Infocyph\Omnibus\Handler\HandlerInvoker;
use Infocyph\Omnibus\Handler\HandlerMap;
use Infocyph\Omnibus\Routing\Route;
use Infocyph\Omnibus\Routing\RouteMap;
use Infocyph\Omnibus\Scheduling\MessageFactoryMap;
use Infocyph\Omnibus\Transport\InMemoryTransport;
use Infocyph\Omnibus\Transport\SyncTransport;
use Infocyph\Omnibus\Transport\TransportRegistry;

final class MessagingGraphFactory
{
    public static function consumer(ConsumerFactory $factory): Consumer
    {
        return $factory->make();
    }

    public static function handlerInvoker(
        MessagingRuntimeResolver $resolver,
        HandlerMap $handlers,
        mixed $handlerMiddleware,
        mixed $jobMiddleware,
    ): HandlerInvoker {
        return new HandlerInvoker(
            $handlers,
            $resolver->handlerMiddleware($handlerMiddleware, $jobMiddleware),
        );
    }

    public static function handlerMap(MessagingRuntimeResolver $resolver, mixed $configured): HandlerMap
    {
        return new HandlerMap($resolver->handlers($configured));
    }

    public static function listenerMap(MessagingRuntimeResolver $resolver, mixed $configured): ListenerMap
    {
        return new ListenerMap($resolver->listeners($configured));
    }

    public static function messageFactoryMap(
        MessagingRuntimeResolver $resolver,
        mixed $configured,
    ): MessageFactoryMap {
        return new MessageFactoryMap($resolver->scheduledMessages($configured));
    }

    public static function routeMap(mixed $configured, mixed $default): RouteMap
    {
        if (!is_array($configured)) {
            $configured = [];
        }
        if (!is_array($default)) {
            $default = [];
        }

        $routes = [];
        foreach ($configured as $message => $definition) {
            if (!is_string($message) || (!class_exists($message) && !interface_exists($message))) {
                throw new \InvalidArgumentException('Messaging route keys must be message class names.');
            }
            $routes[$message] = self::route($definition);
        }

        return new RouteMap($routes, self::route($default));
    }

    public static function transports(
        SyncTransport $sync,
        InMemoryTransport $memory,
    ): TransportRegistry {
        return new TransportRegistry([
            'sync' => $sync,
            'memory' => $memory,
        ]);
    }

    private static function route(mixed $definition): Route
    {
        $definition = is_array($definition) ? $definition : [];
        $delay = $definition['delay_seconds'] ?? null;

        return new Route(
            transport: ValueNormalizer::string($definition['transport'] ?? null, 'sync'),
            queue: ValueNormalizer::string($definition['queue'] ?? null, 'default'),
            delaySeconds: is_numeric($delay) ? (float) $delay : 0.0,
        );
    }
}
