<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Communication\CommunicationProfiles;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientFactory;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Integration\Runwire\RunwireBinding;
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
    if (!is_string($payload)) {
        throw new RuntimeException('Expected a serialized webhook request payload.');
    }
    $headers = [];
    foreach ([WebhookHeaders::SIGNATURE, WebhookHeaders::TIMESTAMP, WebhookHeaders::EVENT, WebhookHeaders::DELIVERY] as $name) {
        $value = $request->headers->get($name);
        if (!is_string($value)) {
            throw new RuntimeException('Expected a string webhook header: ' . $name);
        }
        $headers[$name] = $value;
    }
    $event = $profiles->webhookReceiver()->receive($payload, $headers);
    if ($delivery->delivery === null) {
        throw new RuntimeException('Webhook delivery was unexpectedly absent.');
    }

    expect($headers[WebhookHeaders::SIGNATURE])->toContain('v2=')
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
        '/orders.v1.OrderService/Create' => static fn(GrpcInboundRequest $request): GrpcInboundResponse => GrpcInboundResponse::ok(
            ['accepted' => $request->message],
        ),
    ]);
    $response = $dispatcher->receive('/orders.v1.OrderService/Create', ['order_id' => 1001]);

    expect($result->successful)->toBeTrue()
        ->and($result->response)->toBeInstanceOf(GrpcResponse::class);
    if (!$result->response instanceof GrpcResponse) {
        throw new \UnexpectedValueException('gRPC call must return a typed response.');
    }
    expect($result->response->message)->toBe(['echo' => ['order_id' => 1001]])
        ->and($response->isOk())->toBeTrue()
        ->and($response->message)->toBe(['accepted' => ['order_id' => 1001]]);
});

it('preserves TalkingBytes 2.3.1 resolved middleware composition through the typed HTTP base path', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => dirname(__DIR__, 2)],
        'communication' => [
            'http' => [
                'default_client' => 'api',
                'clients' => [
                    'api' => [
                        'timeoutSeconds' => 17,
                        'connectTimeoutSeconds' => 4,
                        'verifyPeer' => true,
                        'verifyHost' => true,
                        'retry' => [
                            'enabled' => true,
                            'attempts' => 2,
                            'base_delay_ms' => 0,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $profiles = $app->make(CommunicationProfiles::class);
    $config = $profiles->httpConfig();
    $client = $profiles->http();

    expect($config->timeoutSeconds)->toBe(17)
        ->and($config->connectTimeoutSeconds)->toBe(4)
        ->and($config->verifyPeer)->toBeTrue()
        ->and($config->verifyHost)->toBeTrue()
        ->and($client->hasRetryMiddleware())->toBeTrue();
});

it('binds current Runwire context per HTTP and gRPC profile creation without retaining it for later ordinary operations', function (): void {
    $profiles = new CommunicationProfiles(new ConfigRepository([
        'communication' => [
            'http' => [
                'default_client' => 'api',
                'clients' => ['api' => ['retry' => ['enabled' => true, 'attempts' => 3, 'base_delay_ms' => 0]]],
            ],
            'grpc' => ['default_profile' => 'default', 'profiles' => ['default' => []]],
        ],
    ]));
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $caller = static fn(GrpcRequest $message): GrpcResponse => new GrpcResponse(GrpcStatus::Ok, $message->message);

    expect($profiles->http())->not->toBe($profiles->http());

    try {
        CacheRunwire::share($request, null, static function () use ($profiles, $caller, $request): void {
            expect(CacheRunwire::current()?->request)->toBe($request)
                ->and($profiles->http())->not->toBe($profiles->http())
                ->and($profiles->grpc($caller))->not->toBe($profiles->grpc($caller));

            $request->cancel(CancellationReason::HOST_CANCELLED);

            expect(fn() => $profiles->http())->toThrow(CancelledException::class)
                ->and(fn() => $profiles->grpc($caller))->toThrow(CancelledException::class);
        }, $runtime);
    } finally {
        $request->complete();
    }

    expect(CacheRunwire::current())->toBeNull()
        ->and($profiles->http())->not->toBe($profiles->http())
        ->and($profiles->grpc($caller))->not->toBe($profiles->grpc($caller));
});

it('prevents a second native TalkingBytes HTTP attempt when a borrowed host cancels during the first retryable response', function (): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $transport = new class ($request) implements HttpTransport {
        public int $attempts = 0;

        public ?string $url = null;

        public function __construct(private RequestContext $request) {}

        #[\Override]
        public function send(HttpRequest $request): CommunicationResult
        {
            ++$this->attempts;
            $this->url = $request->buildUrl();
            $this->request->cancel(CancellationReason::HOST_CANCELLED);

            return CommunicationResult::failure('Service unavailable.', statusCode: 503);
        }
    };

    try {
        $client = new HttpClientFactory()->withRunwire($runtime, $request)->fromArray(
            ['retry' => ['enabled' => true, 'attempts' => 4, 'base_delay_ms' => 0]],
            $transport,
        );
        $result = $client->get('https://api.example.test/retryable');

        expect($result->successful)->toBeFalse()
            ->and($result->metadata['cancelled'] ?? null)->toBeTrue()
            ->and($transport->attempts)->toBe(1)
            ->and($transport->url)->toBe('https://api.example.test/retryable');
    } finally {
        $request->complete();
    }
});

it('retains host deadline and terminal cancellation after live HTTP and gRPC profile selection changes', function (): void {
    $config = new ConfigRepository([
        'communication' => [
            'http' => [
                'default_client' => 'api',
                'clients' => [
                    'api' => ['timeoutSeconds' => 20],
                    'rotated' => ['timeoutSeconds' => 180, 'retry' => [
                        'enabled' => true, 'attempts' => 4, 'base_delay_ms' => 0,
                    ]],
                ],
            ],
            'grpc' => [
                'default_profile' => 'api',
                'profiles' => [
                    'api' => ['retry' => ['enabled' => false]],
                    'rotated' => ['retry' => ['enabled' => true, 'attempts' => 3, 'base_delay_ms' => 0]],
                ],
            ],
        ],
    ]);
    $profiles = new CommunicationProfiles($config);
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime, new RequestExecutionPolicy(maxExecutionSeconds: 30.0));
    $caller = static fn(GrpcRequest $message): GrpcResponse => new GrpcResponse(GrpcStatus::Ok, $message->message);

    try {
        CacheRunwire::share($request, null, static function () use ($config, $profiles, $runtime, $request, $caller): void {
            $binding = new RunwireBinding($runtime, $request);
            $deadline = $binding->deadline();
            expect($deadline)->not->toBeNull()
                ->and($deadline?->remainingSeconds())->toBeGreaterThan(0.0)
                ->toBeLessThan(30.0)
                ->and($profiles->httpConfig()->timeoutSeconds)->toBe(20)
                ->and($profiles->http())->not->toBe($profiles->http())
                ->and($profiles->grpc($caller))->not->toBe($profiles->grpc($caller));

            expect($config->set('communication.http.default_client', 'rotated'))->toBeTrue()
                ->and($config->set('communication.grpc.default_profile', 'rotated'))->toBeTrue()
                ->and($profiles->httpConfig()->timeoutSeconds)->toBe(180)
                ->and($profiles->http()->hasRetryMiddleware())->toBeTrue()
                ->and($profiles->grpc($caller))->not->toBe($profiles->grpc($caller))
                ->and((new RunwireBinding($runtime, $request))->deadline()?->remainingSeconds())
                ->toBeLessThan(30.0);

            $request->cancel(CancellationReason::HOST_CANCELLED);
            expect(fn(): HttpClient => $profiles->http())->toThrow(CancelledException::class)
                ->and(fn(): object => $profiles->grpc($caller))->toThrow(CancelledException::class)
                ->and(fn(): HttpClient => $profiles->http('api'))->toThrow(CancelledException::class);
        }, $runtime);
    } finally {
        $request->complete();
    }

    expect(CacheRunwire::current())->toBeNull()
        ->and($profiles->httpConfig()->timeoutSeconds)->toBe(180)
        ->and($profiles->http())->not->toBe($profiles->http())
        ->and($profiles->grpc($caller))->not->toBe($profiles->grpc($caller));
});

it('keeps Foundation production TLS policy when using TalkingBytes 2.3.1 typed composition', function (): void {
    $profiles = new CommunicationProfiles(new ConfigRepository([
        'app' => [
            'env' => 'production',
        ],
        'communication' => [
            'http' => [
                'default_client' => 'api',
                'clients' => [
                    'api' => [
                        'verifyPeer' => false,
                        'verifyHost' => true,
                    ],
                ],
            ],
        ],
    ]));

    expect(fn() => $profiles->http())
        ->toThrow(LogicException::class, 'Production HTTP profiles must verify both TLS peers and hosts.');
});
