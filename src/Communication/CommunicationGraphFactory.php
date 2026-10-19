<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundHandlerInterface;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\WebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;
use Psr\Container\ContainerInterface;

final class CommunicationGraphFactory
{
    public static function grpcInbound(
        CommunicationProfiles $profiles,
        ContainerInterface $services,
        mixed $configured,
    ): GrpcInboundDispatcher {
        if (!is_array($configured)) {
            throw new \InvalidArgumentException('communication.grpc.inbound.handlers must be a method-to-service map.');
        }

        $handlers = [];
        foreach ($configured as $method => $service) {
            if (!is_string($method) || trim($method) === '' || !is_string($service) || trim($service) === '') {
                throw new \InvalidArgumentException(
                    'communication.grpc.inbound.handlers must map non-empty method names to service identifiers.',
                );
            }

            $handlers[$method] = static fn(GrpcInboundRequest $request): GrpcInboundResponse => self::invokeGrpcHandler($services, $service, $request);
        }

        return $profiles->grpcInbound($handlers);
    }

    public static function http(CommunicationProfiles $profiles): HttpClient
    {
        return $profiles->http();
    }

    public static function httpConfig(CommunicationProfiles $profiles): HttpClientConfig
    {
        return $profiles->httpConfig();
    }

    public static function protectedWebhookReceiver(
        CommunicationProfiles $profiles,
        ConfigRepository $config,
        ContainerInterface $services,
    ): WebhookReceiver {
        $profile = $config->get('communication.webhooks.default_inbound', 'default');
        $profile = is_string($profile) && trim($profile) !== '' ? trim($profile) : 'default';
        $configured = $config->get('communication.webhooks.inbound.' . $profile, []);
        $configured = is_array($configured) ? $configured : [];
        $replay = ValueNormalizer::associativeArray($configured['replay'] ?? []);
        $store = $replay['store'] ?? $config->get('cache.default');
        $store = is_string($store) && trim($store) !== '' ? trim($store) : null;
        if ($store === null) {
            throw new \LogicException('Webhook replay protection requires a configured CacheLayer store.');
        }

        $ttl = max(1, ValueNormalizer::int($replay['ttl_seconds'] ?? null, 86_400));
        $replayStore = new CacheLayerWebhookReplayStore(static function () use ($services, $store): CacheInterface {
            $cache = $services->get(CacheManager::class);
            if (!$cache instanceof CacheManager) {
                throw new \LogicException('Webhook replay CacheManager binding is invalid.');
            }

            return $cache->store($store);
        });

        return $profiles->webhookReceiver($profile, $replayStore, $ttl);
    }

    public static function webhookReceiver(
        CommunicationProfiles $profiles,
        ConfigRepository $config,
    ): WebhookReceiver {
        $profile = $config->get('communication.webhooks.default_inbound', 'default');
        $profile = is_string($profile) && trim($profile) !== '' ? trim($profile) : 'default';

        return $profiles->webhookReceiver($profile);
    }

    public static function webhookSender(CommunicationProfiles $profiles): WebhookSender
    {
        return $profiles->webhookSender();
    }

    public static function webhookVerifier(CommunicationProfiles $profiles): WebhookVerifier
    {
        return $profiles->webhookVerifier();
    }

    private static function invokeGrpcHandler(
        ContainerInterface $services,
        string $service,
        GrpcInboundRequest $request,
    ): GrpcInboundResponse {
        $handler = $services->get($service);
        if ($handler instanceof GrpcInboundHandlerInterface) {
            return $handler->handle($request);
        }
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException(sprintf(
                'Configured gRPC handler service "%s" must be callable or implement %s.',
                $service,
                GrpcInboundHandlerInterface::class,
            ));
        }
        $response = $handler($request);

        return $response instanceof GrpcInboundResponse
            ? $response
            : throw new \UnexpectedValueException('Configured gRPC handlers must return GrpcInboundResponse.');
    }
}
