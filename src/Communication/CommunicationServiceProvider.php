<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Http\HttpClient;
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

        $this->bindFactory($container, CommunicationManager::class, fn() => new CommunicationManager(
            config: $app->config(),
            container: $container,
        ), LifetimeEnum::Singleton);

        if (!$this->hasExplicitBinding($container, HttpClient::class)) {
            $this->bindFactory(
                $container,
                HttpClient::class,
                fn(): HttpClient => $this->manager($container)->httpClient(),
                LifetimeEnum::Singleton,
            );
        }
        $this->bindFactory(
            $container,
            WebhookSender::class,
            fn(): WebhookSender => $this->manager($container)->webhookSender(),
            LifetimeEnum::Singleton,
        );
        $this->bindFactory(
            $container,
            WebhookVerifier::class,
            fn(): WebhookVerifier => $this->manager($container)->webhookVerifier(),
            LifetimeEnum::Singleton,
        );
        $this->bindFactory(
            $container,
            WebhookReceiver::class,
            fn(): WebhookReceiver => $this->manager($container)->webhookReceiver(),
            LifetimeEnum::Singleton,
        );
        $this->bindFactory(
            $container,
            GrpcInboundDispatcher::class,
            fn(): GrpcInboundDispatcher => $this->manager($container)->grpcInboundDispatcher(),
            LifetimeEnum::Singleton,
        );
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
