<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundHandlerInterface;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\WebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;

final class CommunicationServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        if (!class_exists(HttpClient::class)) {
            throw new \LogicException(
                'Foundation communication services require infocyph/talkingbytes; run "php infbyte module:install communication".',
            );
        }

        $container = $app->container();
        $this->bindFactory(
            $container,
            CommunicationProfiles::class,
            fn() => new CommunicationProfiles($app->config()),
            LifetimeEnum::Singleton,
        );

        if (!$this->hasExplicitBinding($container, HttpClientConfig::class)) {
            $this->bindFactory(
                $container,
                HttpClientConfig::class,
                fn(): HttpClientConfig => $app->make(CommunicationProfiles::class)->httpConfig(),
                LifetimeEnum::Singleton,
            );
        }
        if (!$this->hasExplicitBinding($container, HttpClient::class)) {
            $this->bindFactory(
                $container,
                HttpClient::class,
                fn(): HttpClient => $app->make(CommunicationProfiles::class)->http(),
                LifetimeEnum::Scoped,
            );
        }
        if (!$this->hasExplicitBinding($container, WebhookSender::class)) {
            $this->bindFactory(
                $container,
                WebhookSender::class,
                fn(): WebhookSender => $app->make(CommunicationProfiles::class)->webhookSender(),
                LifetimeEnum::Scoped,
            );
        }
        if (!$this->hasExplicitBinding($container, WebhookVerifier::class)) {
            $this->bindFactory(
                $container,
                WebhookVerifier::class,
                fn(): WebhookVerifier => $app->make(CommunicationProfiles::class)->webhookVerifier(),
                LifetimeEnum::Singleton,
            );
        }
        if (!$this->hasExplicitBinding($container, WebhookReceiver::class)) {
            $this->bindFactory(
                $container,
                WebhookReceiver::class,
                fn(): WebhookReceiver => $this->webhookReceiver($app),
                LifetimeEnum::Singleton,
            );
        }
        if (!$this->hasExplicitBinding($container, GrpcInboundDispatcher::class)) {
            $this->bindFactory(
                $container,
                GrpcInboundDispatcher::class,
                fn(): GrpcInboundDispatcher => $this->grpcInboundDispatcher($app),
                LifetimeEnum::Scoped,
            );
        }

        $this->bindFactory(
            $container,
            'foundation.communication',
            fn() => $app->make(CommunicationProfiles::class),
            LifetimeEnum::Singleton,
        );
    }

    private function grpcInboundDispatcher(Application $app): GrpcInboundDispatcher
    {
        $configured = $app->config()->get('communication.grpc.inbound.handlers', []);
        if (!is_array($configured)) {
            throw new \InvalidArgumentException(
                'communication.grpc.inbound.handlers must be a method-to-service map.',
            );
        }

        $handlers = [];
        foreach ($configured as $method => $service) {
            if (!is_string($method) || trim($method) === '' || !is_string($service) || trim($service) === '') {
                throw new \InvalidArgumentException(
                    'communication.grpc.inbound.handlers must map non-empty method names to service identifiers.',
                );
            }

            $handler = $app->make($service);
            if ($handler instanceof GrpcInboundHandlerInterface) {
                $handlers[$method] = $handler->handle(...);

                continue;
            }
            if (is_callable($handler)) {
                $handlers[$method] = $handler;

                continue;
            }

            throw new \InvalidArgumentException(sprintf(
                'Configured gRPC handler service "%s" must be callable or implement %s.',
                $service,
                GrpcInboundHandlerInterface::class,
            ));
        }

        return $app->make(CommunicationProfiles::class)->grpcInbound($handlers);
    }

    private function webhookReceiver(Application $app): WebhookReceiver
    {
        $profile = $app->config()->get('communication.webhooks.default_inbound', 'default');
        $profile = is_string($profile) && trim($profile) !== '' ? trim($profile) : 'default';
        $configured = $app->config()->get('communication.webhooks.inbound.' . $profile, []);
        $configured = is_array($configured) ? $configured : [];
        $replay = ValueNormalizer::associativeArray($configured['replay'] ?? []);
        $enabled = ValueNormalizer::bool($replay['enabled'] ?? null, $app->config()->isProduction());

        if ($app->config()->isProduction() && !$enabled) {
            throw new \LogicException('Production WebhookReceiver requires replay protection.');
        }
        if (!$enabled) {
            return $app->make(CommunicationProfiles::class)->webhookReceiver($profile);
        }
        if (!class_exists(Cache::class)) {
            throw new \LogicException(
                'Webhook replay protection requires infocyph/cachelayer; install the cache module or bind a custom WebhookReceiver.',
            );
        }

        $store = $replay['store'] ?? $app->config()->get('cache.default');
        $store = is_string($store) && trim($store) !== '' ? trim($store) : null;
        if ($store === null) {
            throw new \LogicException('Webhook replay protection requires a configured CacheLayer store.');
        }

        $factory = $app->make(CacheLayerFactory::class);
        $ttl = max(1, ValueNormalizer::int($replay['ttl_seconds'] ?? null, 86_400));
        $replayStore = new CacheLayerWebhookReplayStore(
            $factory->make($store),
            $factory->lock($store),
        );

        return $app->make(CommunicationProfiles::class)->webhookReceiver($profile, $replayStore, $ttl);
    }
}
