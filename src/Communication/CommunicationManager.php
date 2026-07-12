<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\Foundation\Support\AbstractContainerManager;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Pipeline\MiddlewarePipeline;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcServer;
use Infocyph\TalkingBytes\Grpc\Native\GeneratedStubGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcStreamingInvoker;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;
use Infocyph\TalkingBytes\Http\Concurrent\RequestPool;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Signing\HmacSha256Signer;
use Infocyph\TalkingBytes\Signing\RequestSignerInterface;
use Infocyph\TalkingBytes\Signing\SignatureVerifier;
use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Testing\FakeWebhookSender;
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\WebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;

final readonly class CommunicationManager extends AbstractContainerManager
{
    /**
     * @param null|callable(string, array<string, mixed>):void $listener
     */
    public function events(?callable $listener): void
    {
        CommunicationEventBus::listen($listener);
    }

    public function fakeHttp(?FakeHttpTransport $transport = null): HttpClient
    {
        return HttpClient::fake($transport);
    }

    public function fakeWebhook(): FakeWebhookSender
    {
        return Webhook::fake();
    }

    /**
     * @param callable(\Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest):\Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse $caller
     */
    public function grpcClient(callable $caller, ?string $profile = null): GrpcClient
    {
        return $this->applyGrpcProfile(GrpcClient::using($caller), $profile);
    }

    /** @param array<string, string> $methodMap */
    public function grpcGeneratedStubClient(object $stubClient, array $methodMap = [], ?string $profile = null): GrpcClient
    {
        $invoker = new GeneratedStubGrpcInvoker($stubClient, $methodMap);

        return $this->grpcNativeClient($invoker, $invoker, $profile);
    }

    public function grpcNativeClient(
        NativeGrpcInvoker $invoker,
        ?NativeGrpcStreamingInvoker $streamingInvoker = null,
        ?string $profile = null,
    ): GrpcClient {
        $client = $streamingInvoker instanceof NativeGrpcStreamingInvoker
            ? GrpcClient::usingNativeStreaming($invoker, $streamingInvoker)
            : GrpcClient::usingNative($invoker);

        return $this->applyGrpcProfile($client, $profile);
    }

    /**
     * @param array<string, callable(\Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest):\Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse> $handlers
     */
    public function grpcServer(array $handlers = []): GrpcServer
    {
        return new GrpcServer($handlers);
    }

    public function hmacSigner(string $secret): HmacSha256Signer
    {
        return new HmacSha256Signer($secret);
    }

    public function httpClient(?string $profile = null): HttpClient
    {
        $config = $this->httpProfileConfig($profile);
        $client = HttpClient::fromConfig(HttpClientConfig::fromArray($config));

        return $this->applyHttpDecorators($client, $config);
    }

    public function httpConfig(?string $profile = null): HttpClientConfig
    {
        return HttpClientConfig::fromArray($this->httpProfileConfig($profile));
    }

    public function httpPool(int $maxConcurrency = 10): RequestPool
    {
        return HttpClient::multi($maxConcurrency);
    }

    /** @param list<MiddlewareInterface> $middlewares */
    public function pipeline(TransportInterface $transport, array $middlewares = []): MiddlewarePipeline
    {
        return new MiddlewarePipeline($transport, $middlewares);
    }

    public function signatureVerifier(RequestSignerInterface $signer): SignatureVerifier
    {
        return new SignatureVerifier($signer);
    }

    public function useEventDispatcher(EventDispatcher $dispatcher): void
    {
        CommunicationEventBus::useDispatcher($dispatcher);
    }

    public function webhookReceiver(
        ?string $profile = null,
        ?WebhookReplayStore $replayStore = null,
        ?int $replayTtlSeconds = null,
    ): WebhookReceiver {
        $config = $this->webhookInboundProfileConfig($profile);
        $receiver = Webhook::receiver(
            $this->stringValue($config, 'secret', 'change-me'),
            $this->intValue($config, 'max_age_seconds', 300),
        );

        if (!$replayStore instanceof WebhookReplayStore) {
            return $receiver;
        }

        return $receiver->withReplayStore($replayStore, $replayTtlSeconds ?? 86400);
    }

    public function webhookSender(?string $profile = null): WebhookSender
    {
        $config = $this->webhookOutboundProfileConfig($profile);
        $httpProfile = $this->stringValue(
            $config,
            'http_client',
            $this->stringConfig('http.default_client', 'default'),
        );

        return $this->webhookSenderUsing($this->httpClient($httpProfile), $profile);
    }

    public function webhookSenderUsing(HttpClient $httpClient, ?string $profile = null): WebhookSender
    {
        $config = $this->webhookOutboundProfileConfig($profile);
        $sender = Webhook::sender($httpClient);
        $secret = $this->nullableStringValue($config, 'signing_secret');

        if ($secret !== null) {
            $sender = $sender->withSecret($secret);
        }

        $retry = $this->arrayValue($config, 'retry');
        if ($this->boolValue($retry, 'enabled', false)) {
            $sender = $sender->withRetryProfile(
                $this->intValue($retry, 'attempts', 3),
                $this->intValue($retry, 'base_delay_ms', 250),
                $this->intValue($retry, 'max_retry_after_seconds', 30),
            );
        }

        return $sender;
    }

    public function webhookVerifier(
        ?string $profile = null,
        ?string $secret = null,
        ?int $maxAgeSeconds = null,
    ): WebhookVerifier {
        $config = $this->webhookInboundProfileConfig($profile);

        return Webhook::verifier(
            $secret ?? $this->stringValue($config, 'secret', 'change-me'),
            $maxAgeSeconds ?? $this->intValue($config, 'max_age_seconds', 300),
        );
    }

    protected function configSection(): string
    {
        return 'communication';
    }

    private function applyGrpcProfile(GrpcClient $client, ?string $profile = null): GrpcClient
    {
        $retry = $this->arrayValue($this->grpcProfileConfig($profile), 'retry');
        if (!$this->boolValue($retry, 'enabled', false)) {
            return $client;
        }

        return $client->withGrpcRetry(GrpcRetryPolicy::standard(
            $this->intValue($retry, 'attempts', 3),
            $this->intValue($retry, 'base_delay_ms', 100),
            $this->nullableIntValue($retry, 'max_delay_ms'),
            $this->floatValue($retry, 'jitter_ratio', 0.0),
        ));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyHttpAuth(HttpClient $client, array $config): HttpClient
    {
        $auth = $this->arrayValue($config, 'auth');

        return match ($this->stringValue($auth, 'driver', 'none')) {
            'api_key', 'api_key_header', 'api-key-header', 'header' => $client->withApiKeyHeader(
                $this->stringValue($auth, 'header', 'X-Api-Key'),
                $this->stringValue($auth, 'value'),
            ),
            'api_key_query', 'api-key-query', 'query' => $client->withApiKeyQuery(
                $this->stringValue($auth, 'query_key', 'api_key'),
                $this->stringValue($auth, 'value'),
            ),
            'basic' => $client->withBasicAuth(
                $this->stringValue($auth, 'username'),
                $this->stringValue($auth, 'password'),
            ),
            'bearer' => $client->withBearerToken($this->stringValue($auth, 'token')),
            default => $client,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyHttpCircuitBreaker(HttpClient $client, array $config): HttpClient
    {
        $circuitBreaker = $this->arrayValue($config, 'circuit_breaker');
        if (!$this->boolValue($circuitBreaker, 'enabled', false)) {
            return $client;
        }

        return $client->withCircuitBreaker(new CircuitBreaker(
            $this->intValue($circuitBreaker, 'failure_threshold', 5),
            $this->intValue($circuitBreaker, 'cool_down_seconds', 30),
        ));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyHttpCookies(HttpClient $client, array $config): HttpClient
    {
        $cookies = $this->arrayValue($config, 'cookies');

        return $this->boolValue($cookies, 'enabled', false)
            ? $client->withCookieJar(new CookieJar())
            : $client;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyHttpDecorators(HttpClient $client, array $config): HttpClient
    {
        $client = $this->applyHttpAuth($client, $config);
        $client = $this->applyHttpCookies($client, $config);
        $client = $this->applyHttpRetry($client, $config);
        $client = $this->applyHttpRateLimit($client, $config);
        $client = $this->applyHttpCircuitBreaker($client, $config);

        return $this->applyHttpIdempotency($client, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyHttpIdempotency(HttpClient $client, array $config): HttpClient
    {
        $idempotency = $this->arrayValue($config, 'idempotency');
        if (!$this->boolValue($idempotency, 'enabled', false)) {
            return $client;
        }

        return $client->withIdempotency(
            $this->stringValue($idempotency, 'header', 'Idempotency-Key'),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyHttpRateLimit(HttpClient $client, array $config): HttpClient
    {
        $rateLimit = $this->arrayValue($config, 'rate_limit');
        if (!$this->boolValue($rateLimit, 'enabled', false)) {
            return $client;
        }

        return $client->withRateLimit(new RateLimiter(
            $this->intValue($rateLimit, 'max_requests', 60),
            $this->intValue($rateLimit, 'per_seconds', 60),
        ));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyHttpRetry(HttpClient $client, array $config): HttpClient
    {
        $retry = $this->arrayValue($config, 'retry');
        if (!$this->boolValue($retry, 'enabled', false)) {
            return $client;
        }

        return $client->withHttpRetry(HttpRetryPolicy::standard(
            $this->intValue($retry, 'attempts', 3),
            $this->intValue($retry, 'base_delay_ms', 250),
            $this->intValue($retry, 'max_retry_after_seconds', 30),
        ));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function arrayValue(array $config, string $key): array
    {
        return ValueNormalizer::associativeArray($config[$key] ?? []);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boolValue(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_string($value) => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            default => $default,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function floatValue(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? $default;

        return is_numeric($value)
            ? (float) $value
            : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function grpcProfileConfig(?string $profile = null): array
    {
        $resolvedProfile = $profile ?? $this->stringConfig('grpc.default_profile', 'default');

        return ValueNormalizer::associativeArray(
            $this->config('grpc.profiles.' . $resolvedProfile, []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function httpProfileConfig(?string $profile = null): array
    {
        $resolvedProfile = $profile ?? $this->stringConfig('http.default_client', 'default');

        return ValueNormalizer::associativeArray(
            $this->config('http.clients.' . $resolvedProfile, []),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function intValue(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        return is_numeric($value)
            ? (int) $value
            : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function nullableIntValue(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value)
            ? (int) $value
            : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function nullableStringValue(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = $this->config($key, $default);

        return is_string($value)
            ? $value
            : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function stringValue(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;

        return is_string($value)
            ? $value
            : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookInboundProfileConfig(?string $profile = null): array
    {
        $resolvedProfile = $profile ?? $this->stringConfig('webhooks.default_inbound', 'default');

        return ValueNormalizer::associativeArray(
            $this->config('webhooks.inbound.' . $resolvedProfile, []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookOutboundProfileConfig(?string $profile = null): array
    {
        $resolvedProfile = $profile ?? $this->stringConfig('webhooks.default_outbound', 'default');

        return ValueNormalizer::associativeArray(
            $this->config('webhooks.outbound.' . $resolvedProfile, []),
        );
    }
}
