<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Communication\CommunicationManager;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcServer;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcStreamingInvoker;
use Infocyph\TalkingBytes\Http\Concurrent\RequestPool;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Testing\FakeWebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\WebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;

final class Comms extends Facade
{
    /**
     * @param null|callable(string, array<string, mixed>):void $listener
     */
    public static function events(?callable $listener): void
    {
        self::manager()->events($listener);
    }

    public static function fakeHttp(?FakeHttpTransport $transport = null): HttpClient
    {
        return self::manager()->fakeHttp($transport);
    }

    public static function fakeWebhook(): FakeWebhookSender
    {
        return self::manager()->fakeWebhook();
    }

    /**
     * @param callable(\Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest):\Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse $caller
     */
    public static function grpcClient(callable $caller, ?string $profile = null): GrpcClient
    {
        return self::manager()->grpcClient($caller, $profile);
    }

    public static function grpcNativeClient(
        NativeGrpcInvoker $invoker,
        ?NativeGrpcStreamingInvoker $streamingInvoker = null,
        ?string $profile = null,
    ): GrpcClient {
        return self::manager()->grpcNativeClient($invoker, $streamingInvoker, $profile);
    }

    /**
     * @param array<string, callable(\Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest):\Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse> $handlers
     */
    public static function grpcServer(array $handlers = []): GrpcServer
    {
        return self::manager()->grpcServer($handlers);
    }

    public static function httpClient(?string $profile = null): HttpClient
    {
        return self::manager()->httpClient($profile);
    }

    public static function httpConfig(?string $profile = null): HttpClientConfig
    {
        return self::manager()->httpConfig($profile);
    }

    public static function httpPool(int $maxConcurrency = 10): RequestPool
    {
        return self::manager()->httpPool($maxConcurrency);
    }

    public static function manager(): CommunicationManager
    {
        return self::app()->communication();
    }

    public static function useEventDispatcher(EventDispatcher $dispatcher): void
    {
        self::manager()->useEventDispatcher($dispatcher);
    }

    public static function webhookReceiver(
        ?string $profile = null,
        ?WebhookReplayStore $replayStore = null,
        ?int $replayTtlSeconds = null,
    ): WebhookReceiver {
        return self::manager()->webhookReceiver($profile, $replayStore, $replayTtlSeconds);
    }

    public static function webhookSender(?string $profile = null): WebhookSender
    {
        return self::manager()->webhookSender($profile);
    }

    public static function webhookSenderUsing(HttpClient $httpClient, ?string $profile = null): WebhookSender
    {
        return self::manager()->webhookSenderUsing($httpClient, $profile);
    }

    public static function webhookVerifier(
        ?string $profile = null,
        ?string $secret = null,
        ?int $maxAgeSeconds = null,
    ): WebhookVerifier {
        return self::manager()->webhookVerifier($profile, $secret, $maxAgeSeconds);
    }
}
