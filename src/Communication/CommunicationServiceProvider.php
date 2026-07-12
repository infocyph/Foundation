<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\TalkingBytes\Grpc\GrpcServer;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\WebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;

final class CommunicationServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(CommunicationManager::class, fn() => new CommunicationManager(
            config: $app->config(),
            container: $container,
        ), LifetimeEnum::Singleton);

        $container->bind(HttpClient::class, function () use ($container): HttpClient {
            $manager = $container->get(CommunicationManager::class);
            if (!$manager instanceof CommunicationManager) {
                throw new \RuntimeException('Communication manager must resolve to CommunicationManager.');
            }

            return $manager->httpClient();
        }, LifetimeEnum::Singleton);
        $container->bind(WebhookSender::class, function () use ($container): WebhookSender {
            $manager = $container->get(CommunicationManager::class);
            if (!$manager instanceof CommunicationManager) {
                throw new \RuntimeException('Communication manager must resolve to CommunicationManager.');
            }

            return $manager->webhookSender();
        }, LifetimeEnum::Singleton);
        $container->bind(WebhookVerifier::class, function () use ($container): WebhookVerifier {
            $manager = $container->get(CommunicationManager::class);
            if (!$manager instanceof CommunicationManager) {
                throw new \RuntimeException('Communication manager must resolve to CommunicationManager.');
            }

            return $manager->webhookVerifier();
        }, LifetimeEnum::Singleton);
        $container->bind(WebhookReceiver::class, function () use ($container): WebhookReceiver {
            $manager = $container->get(CommunicationManager::class);
            if (!$manager instanceof CommunicationManager) {
                throw new \RuntimeException('Communication manager must resolve to CommunicationManager.');
            }

            return $manager->webhookReceiver();
        }, LifetimeEnum::Singleton);
        $container->bind(GrpcServer::class, fn() => GrpcServer::new(), LifetimeEnum::Singleton);

        $container->bind('foundation.communication', fn() => $container->get(CommunicationManager::class), LifetimeEnum::Singleton);
    }
}
