<?php

declare(strict_types=1);

use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Foundation;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Webhook\Support\WebhookHeaders;
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

it('resolves configured TalkingBytes HTTP profiles', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => dirname(__DIR__, 2)],
        'communication' => [
            'http' => [
                'default_client' => 'api',
                'clients' => [
                    'api' => [
                        'timeoutSeconds' => 15,
                        'connectTimeoutSeconds' => 5,
                        'userAgent' => 'Infbyte Test Client',
                        'defaultHeaders' => ['X-App' => 'Infbyte'],
                    ],
                ],
            ],
        ],
    ]);

    $config = $app->make(CommunicationProfiles::class)->httpConfig();

    expect($config->timeoutSeconds)->toBe(15)
        ->and($config->connectTimeoutSeconds)->toBe(5)
        ->and($config->userAgent)->toBe('Infbyte Test Client')
        ->and($config->defaultHeaders)->toBe(['X-App' => 'Infbyte']);
});

it('applies TalkingBytes webhook profiles through Foundation composition', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => dirname(__DIR__, 2)],
        'communication' => [
            'http' => [
                'default_client' => 'default',
                'clients' => [
                    'default' => [],
                ],
            ],
            'webhooks' => [
                'default_inbound' => 'default',
                'default_outbound' => 'default',
                'outbound' => [
                    'default' => [
                        'http_client' => 'default',
                        'signing_secret' => 'test-webhook-key',
                    ],
                ],
                'inbound' => [
                    'default' => [
                        'secret' => 'test-webhook-key',
                        'max_age_seconds' => 600,
                    ],
                ],
            ],
        ],
    ]);

    $profiles = $app->make(CommunicationProfiles::class);
    $transport = (new FakeHttpTransport())->pushJson(['ok' => true]);
    $client = HttpClient::fake($transport);
    $sender = Webhook::sender($client)->withSecret('test-webhook-key');

    $delivery = $sender->send(
        WebhookMessage::event('orders.created')
            ->deliveryId(str_repeat('a', 32))
            ->url('https://example.test/hooks')
            ->payload(['order_id' => 1001]),
    );

    $request = $transport->sentRequests()[0];
    $payload = $request->body?->toCurlPayload();
    $event = $profiles->webhookReceiver()->receive((string) $payload, [
        WebhookHeaders::SIGNATURE => (string) $request->headers->get(WebhookHeaders::SIGNATURE),
        WebhookHeaders::TIMESTAMP => (string) $request->headers->get(WebhookHeaders::TIMESTAMP),
        WebhookHeaders::EVENT => (string) $request->headers->get(WebhookHeaders::EVENT),
        WebhookHeaders::DELIVERY => (string) $request->headers->get(WebhookHeaders::DELIVERY),
    ]);

    expect($profiles->webhookSender())->toBeObject()
        ->and($delivery->delivery->delivered)->toBeTrue()
        ->and($event->event)->toBe('orders.created')
        ->and($event->payload)->toBe(['order_id' => 1001]);
});

it('creates TalkingBytes gRPC clients and inbound dispatchers through Foundation profiles', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => dirname(__DIR__, 2)],
        'communication' => [
            'grpc' => [
                'default_profile' => 'default',
                'profiles' => [
                    'default' => [
                        'retry' => [
                            'enabled' => true,
                            'attempts' => 2,
                            'base_delay_ms' => 1,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $profiles = $app->make(CommunicationProfiles::class);
    $client = $profiles->grpc(
        static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(
            GrpcStatus::Ok,
            ['echo' => $request->message],
        ),
    );

    $result = $client->send(new GrpcRequest(
        '/orders.v1.OrderService/Create',
        ['order_id' => 1001],
    ));

    $dispatcher = $profiles->grpcInbound([
        '/orders.v1.OrderService/Create' => static fn($request): GrpcInboundResponse => GrpcInboundResponse::ok(
            ['accepted' => $request->message],
        ),
    ]);
    $response = $dispatcher->receive('/orders.v1.OrderService/Create', ['order_id' => 1001]);

    expect($result->successful)->toBeTrue()
        ->and($result->response)->toBeInstanceOf(GrpcResponse::class)
        ->and($result->response->message)->toBe(['echo' => ['order_id' => 1001]])
        ->and($response->isOk())->toBeTrue()
        ->and($response->message)->toBe(['accepted' => ['order_id' => 1001]]);
});
