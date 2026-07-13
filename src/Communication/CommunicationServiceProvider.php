<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Container;
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

        $container->bind(HttpClient::class, fn(): HttpClient => $this->manager($container)->httpClient(), LifetimeEnum::Singleton);
        $container->bind(WebhookSender::class, fn(): WebhookSender => $this->manager($container)->webhookSender(), LifetimeEnum::Singleton);
        $container->bind(WebhookVerifier::class, fn(): WebhookVerifier => $this->manager($container)->webhookVerifier(), LifetimeEnum::Singleton);
        $container->bind(WebhookReceiver::class, fn(): WebhookReceiver => $this->manager($container)->webhookReceiver(), LifetimeEnum::Singleton);
        $container->bind(GrpcServer::class, fn() => GrpcServer::new(), LifetimeEnum::Singleton);

        $container->bind('foundation.communication', fn() => $container->get(CommunicationManager::class), LifetimeEnum::Singleton);
    }

    private function manager(Container $container): CommunicationManager
    {
        $manager = $container->get(CommunicationManager::class);
        if (!$manager instanceof CommunicationManager) {
            throw new \RuntimeException('Communication manager must resolve to CommunicationManager.');
        }

        return $manager;
    }
}
