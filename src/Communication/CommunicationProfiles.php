<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\Native\GeneratedStubGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcStreamingInvoker;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\WebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;

/**
 * Maps Foundation application profiles to native TalkingBytes protocol objects.
 *
 * TalkingBytes owns protocol execution. Foundation owns only selection and
 * composition of named application profiles.
 */
final readonly class CommunicationProfiles
{
    public function __construct(private ConfigRepository $config) {}

    /** @param callable(GrpcRequest):GrpcResponse $caller */
    public function grpc(callable $caller, ?string $profile = null): GrpcClient
    {
        return $this->applyGrpcRetry(GrpcClient::using($caller), $this->grpcConfig($profile));
    }

    /** @param array<string, string> $methodMap */
    public function grpcGeneratedStub(
        object $stubClient,
        array $methodMap = [],
        ?string $profile = null,
    ): GrpcClient {
        $invoker = new GeneratedStubGrpcInvoker($stubClient, $methodMap);

        return $this->grpcNative($invoker, $invoker, $profile);
    }

    /** @param array<string, callable(GrpcInboundRequest):GrpcInboundResponse> $handlers */
    public function grpcInbound(array $handlers = []): GrpcInboundDispatcher
    {
        return new GrpcInboundDispatcher($handlers);
    }

    public function grpcNative(
        NativeGrpcInvoker $invoker,
        ?NativeGrpcStreamingInvoker $streamingInvoker = null,
        ?string $profile = null,
    ): GrpcClient {
        $client = $streamingInvoker instanceof NativeGrpcStreamingInvoker
            ? GrpcClient::usingNativeStreaming($invoker, $streamingInvoker)
            : GrpcClient::usingNative($invoker);

        return $this->applyGrpcRetry($client, $this->grpcConfig($profile));
    }

    public function http(?string $profile = null): HttpClient
    {
        $config = $this->httpConfigArray($profile);
        $client = HttpClient::fromConfig(HttpClientConfig::fromArray($config));

        return $this->decorateHttp($client, $config);
    }

    public function httpConfig(?string $profile = null): HttpClientConfig
    {
        return HttpClientConfig::fromArray($this->httpConfigArray($profile));
    }

    public function webhookReceiver(
        ?string $profile = null,
        ?WebhookReplayStore $replayStore = null,
        ?int $replayTtlSeconds = null,
    ): WebhookReceiver {
        $config = $this->webhookInboundConfig($profile);
        $secret = $config['secret'] ?? null;
        if (!is_string($secret) && !is_array($secret)) {
            throw new \InvalidArgumentException('Inbound webhook secret must be a string or secret list.');
        }

        $receiver = Webhook::receiver(
            $secret,
            ValueNormalizer::int($config['max_age_seconds'] ?? 300, 300),
        );

        return $replayStore instanceof WebhookReplayStore
            ? $receiver->withReplayStore($replayStore, $replayTtlSeconds ?? 86400)
            : $receiver;
    }

    public function webhookSender(?string $profile = null): WebhookSender
    {
        $config = $this->webhookOutboundConfig($profile);
        $httpProfile = $config['http_client'] ?? $this->defaultProfile('http.default_client', 'default');
        if (!is_string($httpProfile) || trim($httpProfile) === '') {
            throw new \InvalidArgumentException('Outbound webhook http_client must be a non-empty profile name.');
        }

        $sender = Webhook::sender($this->http($httpProfile));
        $secret = $config['signing_secret'] ?? null;
        if (is_string($secret) && $secret !== '') {
            $sender = $sender->withSecret($secret);
        }

        $retry = ValueNormalizer::associativeArray($config['retry'] ?? []);
        if (ValueNormalizer::bool($retry['enabled'] ?? false, false)) {
            $sender = $sender->withRetryProfile(
                ValueNormalizer::int($retry['attempts'] ?? 3, 3),
                ValueNormalizer::int($retry['base_delay_ms'] ?? 250, 250),
                ValueNormalizer::int($retry['max_retry_after_seconds'] ?? 30, 30),
            );
        }

        return $sender;
    }

    public function webhookVerifier(
        ?string $profile = null,
        string|array|null $secret = null,
        ?int $maxAgeSeconds = null,
    ): WebhookVerifier {
        $config = $this->webhookInboundConfig($profile);
        $resolvedSecret = $secret ?? ($config['secret'] ?? null);
        if (!is_string($resolvedSecret) && !is_array($resolvedSecret)) {
            throw new \InvalidArgumentException('Inbound webhook secret must be a string or secret list.');
        }

        return Webhook::verifier(
            $resolvedSecret,
            $maxAgeSeconds ?? ValueNormalizer::int($config['max_age_seconds'] ?? 300, 300),
        );
    }

    /** @param array<string, mixed> $config */
    private function applyGrpcRetry(GrpcClient $client, array $config): GrpcClient
    {
        $retry = ValueNormalizer::associativeArray($config['retry'] ?? []);
        if (!ValueNormalizer::bool($retry['enabled'] ?? false, false)) {
            return $client;
        }

        $maxDelay = $retry['max_delay_ms'] ?? null;

        return $client->withGrpcRetry(GrpcRetryPolicy::standard(
            ValueNormalizer::int($retry['attempts'] ?? 3, 3),
            ValueNormalizer::int($retry['base_delay_ms'] ?? 100, 100),
            is_numeric($maxDelay) ? (int) $maxDelay : null,
            is_numeric($retry['jitter_ratio'] ?? null) ? (float) $retry['jitter_ratio'] : 0.0,
        ));
    }

    /** @param array<string, mixed> $config */
    private function decorateHttp(HttpClient $client, array $config): HttpClient
    {
        $auth = ValueNormalizer::associativeArray($config['auth'] ?? []);
        $client = match ($auth['driver'] ?? 'none') {
            'api_key', 'api_key_header', 'api-key-header', 'header' => $client->withApiKeyHeader(
                $this->requiredString($auth, 'header', 'X-Api-Key'),
                $this->requiredString($auth, 'value'),
            ),
            'api_key_query', 'api-key-query', 'query' => $client->withApiKeyQuery(
                $this->requiredString($auth, 'query_key', 'api_key'),
                $this->requiredString($auth, 'value'),
            ),
            'basic' => $client->withBasicAuth(
                $this->requiredString($auth, 'username'),
                $this->requiredString($auth, 'password'),
            ),
            'bearer' => $client->withBearerToken($this->requiredString($auth, 'token')),
            'none', null => $client,
            default => throw new \InvalidArgumentException('Unsupported communication HTTP auth driver.'),
        };

        $cookies = ValueNormalizer::associativeArray($config['cookies'] ?? []);
        if (ValueNormalizer::bool($cookies['enabled'] ?? false, false)) {
            $client = $client->withCookieJar(new CookieJar());
        }

        $retry = ValueNormalizer::associativeArray($config['retry'] ?? []);
        if (ValueNormalizer::bool($retry['enabled'] ?? false, false)) {
            $client = $client->withHttpRetry(HttpRetryPolicy::standard(
                ValueNormalizer::int($retry['attempts'] ?? 3, 3),
                ValueNormalizer::int($retry['base_delay_ms'] ?? 250, 250),
                ValueNormalizer::int($retry['max_retry_after_seconds'] ?? 30, 30),
            ));
        }

        $rateLimit = ValueNormalizer::associativeArray($config['rate_limit'] ?? []);
        if (ValueNormalizer::bool($rateLimit['enabled'] ?? false, false)) {
            $client = $client->withRateLimit(new RateLimiter(
                ValueNormalizer::int($rateLimit['max_requests'] ?? 60, 60),
                ValueNormalizer::int($rateLimit['per_seconds'] ?? 60, 60),
            ));
        }

        $circuit = ValueNormalizer::associativeArray($config['circuit_breaker'] ?? []);
        if (ValueNormalizer::bool($circuit['enabled'] ?? false, false)) {
            $client = $client->withCircuitBreaker(new CircuitBreaker(
                ValueNormalizer::int($circuit['failure_threshold'] ?? 5, 5),
                ValueNormalizer::int($circuit['cool_down_seconds'] ?? 30, 30),
            ));
        }

        $idempotency = ValueNormalizer::associativeArray($config['idempotency'] ?? []);
        if (ValueNormalizer::bool($idempotency['enabled'] ?? false, false)) {
            $client = $client->withIdempotency(
                $this->requiredString($idempotency, 'header', 'Idempotency-Key'),
            );
        }

        return $client;
    }

    /** @return array<string, mixed> */
    private function grpcConfig(?string $profile): array
    {
        return $this->profile('grpc.profiles', 'grpc.default_profile', $profile);
    }

    /** @return array<string, mixed> */
    private function httpConfigArray(?string $profile): array
    {
        return $this->profile('http.clients', 'http.default_client', $profile);
    }

    private function defaultProfile(string $key, string $fallback): string
    {
        $value = $this->config->get('communication.' . $key, $fallback);

        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    /** @return array<string, mixed> */
    private function profile(string $collectionKey, string $defaultKey, ?string $profile): array
    {
        $name = is_string($profile) && trim($profile) !== ''
            ? trim($profile)
            : $this->defaultProfile($defaultKey, 'default');
        $profiles = $this->config->get('communication.' . $collectionKey, []);
        if (!is_array($profiles) || !isset($profiles[$name]) || !is_array($profiles[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Communication profile "%s" is not configured under %s.',
                $name,
                $collectionKey,
            ));
        }

        return ValueNormalizer::associativeArray($profiles[$name]);
    }

    /** @param array<string, mixed> $config */
    private function requiredString(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('Communication profile key "%s" must be non-empty.', $key));
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function webhookInboundConfig(?string $profile): array
    {
        return $this->profile('webhooks.inbound', 'webhooks.default_inbound', $profile);
    }

    /** @return array<string, mixed> */
    private function webhookOutboundConfig(?string $profile): array
    {
        return $this->profile('webhooks.outbound', 'webhooks.default_outbound', $profile);
    }
}
