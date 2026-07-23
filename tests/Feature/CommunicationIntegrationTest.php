<?php

declare(strict_types=1);

use Infocyph\Foundation\Facades\Comms;
use Infocyph\Foundation\Foundation;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Webhook\Support\WebhookHeaders;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

it('resolves configured talkingbytes http profiles', function (): void {
    $app = Foundation::web([
        'app' => [
            'base_path' => dirname(__DIR__, 2),
        ],
        'communication' => [
            'http' => [
                'default_client' => 'api',
                'clients' => [
                    'api' => [
                        'timeoutSeconds' => 15,
                        'connectTimeoutSeconds' => 5,
                        'userAgent' => 'Infbyte Test Client',
                        'defaultHeaders' => [
                            'X-App' => 'Infbyte',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $config = $app->communication()->httpConfig();

    expect($config->timeoutSeconds)->toBe(15)
        ->and($config->connectTimeoutSeconds)->toBe(5)
        ->and($config->userAgent)->toBe('Infbyte Test Client')
        ->and($config->defaultHeaders)->toBe([
            'X-App' => 'Infbyte',
        ]);

    expect(Comms::httpConfig()->userAgent)->toBe('Infbyte Test Client');
});

it('applies talkingbytes webhook profiles through foundation', function (): void {
    $app = Foundation::web([
        'app' => [
            'base_path' => dirname(__DIR__, 2),
        ],
        'communication' => [
            'webhooks' => [
                'outbound' => [
                    'default' => [
                        'signing_secret' => 'top-secret',
                    ],
                ],
                'inbound' => [
                    'default' => [
                        'secret' => 'top-secret',
                        'max_age_seconds' => 600,
                    ],
                ],
            ],
        ],
    ]);

    $events = [];
    $app->communication()->events(static function (string $event, array $payload) use (&$events): void {
        $events[] = [$event, $payload];
    });

    try {
        $transport = (new FakeHttpTransport())->pushJson(['ok' => true]);
        $client = HttpClient::fake($transport);

        $delivery = $app->communication()->webhookSenderUsing($client)->send(
            WebhookMessage::event('orders.created')
                ->deliveryId(str_repeat('a', 32))
                ->url('https://example.test/hooks')
                ->payload(['order_id' => 1001]),
        );

        $request = $transport->sentRequests()[0];
        $receiver = Comms::webhookReceiver();
        $payload = $request->body?->toCurlPayload();

        $event = $receiver->receive((string) $payload, [
            WebhookHeaders::SIGNATURE => (string) $request->headers->get(WebhookHeaders::SIGNATURE),
            WebhookHeaders::TIMESTAMP => (string) $request->headers->get(WebhookHeaders::TIMESTAMP),
            WebhookHeaders::EVENT => (string) $request->headers->get(WebhookHeaders::EVENT),
            WebhookHeaders::DELIVERY => (string) $request->headers->get(WebhookHeaders::DELIVERY),
        ]);

        expect($delivery->delivery->delivered)->toBeTrue()
            ->and($event->event)->toBe('orders.created')
            ->and($event->payload)->toBe(['order_id' => 1001])
            ->and(array_column($events, 0))->toContain('webhook.send.start', 'webhook.send.finish', 'webhook.verified', 'webhook.received');
    } finally {
        $app->communication()->events(null);
    }
});

it('creates talkingbytes grpc clients and servers through foundation', function (): void {
    $app = Foundation::web([
        'app' => [
            'base_path' => dirname(__DIR__, 2),
        ],
        'communication' => [
            'grpc' => [
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

    $client = $app->communication()->grpcClient(
        static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(
            GrpcStatus::Ok,
            ['echo' => $request->message],
        ),
    );

    $result = $client->send(new GrpcRequest(
        '/orders.v1.OrderService/Create',
        ['order_id' => 1001],
    ));

    $server = Comms::grpcServer([
        '/orders.v1.OrderService/Create' => static fn($request): GrpcInboundResponse => GrpcInboundResponse::ok(
            ['accepted' => $request->message],
        ),
    ]);

    $response = $server->receive('/orders.v1.OrderService/Create', ['order_id' => 1001]);

    expect($result->successful)->toBeTrue()
        ->and($result->response)->toBeInstanceOf(GrpcResponse::class)
        ->and($result->response->message)->toBe([
            'echo' => ['order_id' => 1001],
        ])
        ->and($response->isOk())->toBeTrue()
        ->and($response->message)->toBe([
            'accepted' => ['order_id' => 1001],
        ]);
});
