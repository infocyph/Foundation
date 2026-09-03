<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\WebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;
use Psr\Container\ContainerInterface;

final class CommunicationServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        if (!class_exists(HttpClient::class)) {
            throw new \LogicException(
                'Foundation communication services require infocyph/talkingbytes; run "php infbyte module:install communication".',
            );
        }

        $builder->singleton(CommunicationProfiles::class, FactoryDefinition::construct(
            CommunicationProfiles::class,
            [new ServiceReference(ConfigRepository::class)],
        ));
        if (!$builder->definitions()->has(HttpClientConfig::class)) {
            $builder->singleton(HttpClientConfig::class, FactoryDefinition::staticFactory(
                CommunicationGraphFactory::class,
                'httpConfig',
                [new ServiceReference(CommunicationProfiles::class)],
            ));
        }
        if (!$builder->definitions()->has(HttpClient::class)) {
            $builder->bind(
                HttpClient::class,
                FactoryDefinition::staticFactory(
                    CommunicationGraphFactory::class,
                    'http',
                    [new ServiceReference(CommunicationProfiles::class)],
                ),
                LifetimeEnum::Scoped,
            );
        }
        if (!$builder->definitions()->has(WebhookSender::class)) {
            $builder->bind(
                WebhookSender::class,
                FactoryDefinition::staticFactory(
                    CommunicationGraphFactory::class,
                    'webhookSender',
                    [new ServiceReference(CommunicationProfiles::class)],
                ),
                LifetimeEnum::Scoped,
            );
        }
        if (!$builder->definitions()->has(WebhookVerifier::class)) {
            $builder->singleton(WebhookVerifier::class, FactoryDefinition::staticFactory(
                CommunicationGraphFactory::class,
                'webhookVerifier',
                [new ServiceReference(CommunicationProfiles::class)],
            ));
        }
        if (!$builder->definitions()->has(WebhookReceiver::class)) {
            $this->registerWebhookReceiver($builder, $context);
        }
        if (!$builder->definitions()->has(GrpcInboundDispatcher::class)) {
            $this->registerGrpcDispatcher($builder, $context);
        }

        $builder->alias('foundation.communication', CommunicationProfiles::class);
    }

    private function registerGrpcDispatcher(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $communication = is_array($context->config['communication'] ?? null) ? $context->config['communication'] : [];
        $grpc = is_array($communication['grpc'] ?? null) ? $communication['grpc'] : [];
        $inbound = is_array($grpc['inbound'] ?? null) ? $grpc['inbound'] : [];

        $builder->bind(
            GrpcInboundDispatcher::class,
            FactoryDefinition::staticFactory(
                CommunicationGraphFactory::class,
                'grpcInbound',
                [
                    new ServiceReference(CommunicationProfiles::class),
                    new ServiceReference(ContainerInterface::class),
                    $inbound['handlers'] ?? [],
                ],
            ),
            LifetimeEnum::Scoped,
        );
    }

    private function registerWebhookReceiver(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $communication = is_array($context->config['communication'] ?? null) ? $context->config['communication'] : [];
        $webhooks = is_array($communication['webhooks'] ?? null) ? $communication['webhooks'] : [];
        $profile = $webhooks['default_inbound'] ?? 'default';
        $profile = is_string($profile) && trim($profile) !== '' ? trim($profile) : 'default';
        $inbound = is_array($webhooks['inbound'] ?? null) ? $webhooks['inbound'] : [];
        $profileConfig = is_array($inbound[$profile] ?? null) ? $inbound[$profile] : [];
        $replay = ValueNormalizer::associativeArray($profileConfig['replay'] ?? []);
        $app = is_array($context->config['app'] ?? null) ? $context->config['app'] : [];
        $production = ($app['env'] ?? null) === 'production';
        $enabled = ValueNormalizer::bool($replay['enabled'] ?? null, $production);

        if ($production && !$enabled) {
            throw new \LogicException('Production WebhookReceiver requires replay protection.');
        }
        if (!$enabled) {
            $builder->singleton(WebhookReceiver::class, FactoryDefinition::staticFactory(
                CommunicationGraphFactory::class,
                'webhookReceiver',
                [
                    new ServiceReference(CommunicationProfiles::class),
                    new ServiceReference(ConfigRepository::class),
                ],
            ));
            return;
        }
        if (!class_exists(Cache::class) || !$builder->definitions()->has(CacheLayerFactory::class)) {
            throw new \LogicException(
                'Webhook replay protection requires infocyph/cachelayer and the Foundation cache capability.',
            );
        }

        $builder->singleton(WebhookReceiver::class, FactoryDefinition::staticFactory(
            CommunicationGraphFactory::class,
            'protectedWebhookReceiver',
            [
                new ServiceReference(CommunicationProfiles::class),
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(CacheLayerFactory::class),
            ],
        ));
    }
}
