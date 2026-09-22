<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcClientFactory;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcStreamingInvoker;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\WebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;

/**
 * Maps Foundation application profiles to native TalkingBytes protocol objects.
 *
 * TalkingBytes owns protocol execution and resolved protocol composition.
 * Foundation owns named profile selection, application policy and DI lifetime.
 */
final readonly class CommunicationProfiles
{
    public function __construct(private ConfigRepository $config) {}

    /** @param callable(GrpcRequest):GrpcResponse $caller */
    public function grpc(callable $caller, ?string $profile = null): GrpcClient
    {
        return new GrpcClientFactory()->using($caller, $this->grpcConfig($profile));
    }

    /** @param array<string, string> $methodMap */
    public function grpcGeneratedStub(
        object $stubClient,
        array $methodMap = [],
        ?string $profile = null,
    ): GrpcClient {
        return new GrpcClientFactory()->usingGeneratedStub(
            $stubClient,
            $methodMap,
            $this->grpcConfig($profile),
        );
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
        return new GrpcClientFactory()->usingNative(
            $invoker,
            $streamingInvoker,
            $this->grpcConfig($profile),
        );
    }

    public function http(?string $profile = null): HttpClient
    {
        $array = $this->httpConfigArray($profile);
        $config = HttpClientConfig::fromArray($array);
        if ($this->config->isProduction() && (!$config->verifyPeer || !$config->verifyHost)) {
            throw new \LogicException('Production HTTP profiles must verify both TLS peers and hosts.');
        }

        return HttpClient::fromResolvedConfig($array);
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
        if ($replayStore instanceof WebhookReplayStore) {
            $replay = ValueNormalizer::associativeArray($config['replay'] ?? []);
            $replay['enabled'] = true;
            if ($replayTtlSeconds !== null) {
                $replay['ttl_seconds'] = $replayTtlSeconds;
            }
            $config['replay'] = $replay;
        }

        return Webhook::receiverFromResolvedConfig(
            $this->webhookSecret($config['secret'] ?? null),
            $config,
            $replayStore,
        );
    }

    public function webhookSender(?string $profile = null): WebhookSender
    {
        $config = $this->webhookOutboundConfig($profile);
        $httpProfile = $config['http_client'] ?? $this->defaultProfile('http.default_client', 'default');
        if (!is_string($httpProfile) || trim($httpProfile) === '') {
            throw new \InvalidArgumentException('Outbound webhook http_client must be a non-empty profile name.');
        }

        return Webhook::senderFromResolvedConfig($this->http($httpProfile), $config);
    }

    /** @param list<string>|string|null $secret */
    public function webhookVerifier(
        ?string $profile = null,
        string|array|null $secret = null,
        ?int $maxAgeSeconds = null,
    ): WebhookVerifier {
        $config = $this->webhookInboundConfig($profile);
        if ($maxAgeSeconds !== null) {
            $config['max_age_seconds'] = $maxAgeSeconds;
        }

        return Webhook::verifierFromResolvedConfig(
            $this->webhookSecret($secret ?? ($config['secret'] ?? null)),
            $config,
        );
    }

    private function defaultProfile(string $key, string $fallback): string
    {
        $value = $this->config->get('communication.' . $key, $fallback);

        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
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

    /** @return string|list<string> */
    private function webhookSecret(mixed $secret): string|array
    {
        $single = is_string($secret);
        $values = $single ? [$secret] : (is_array($secret) ? array_values($secret) : []);
        $secrets = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException('Inbound webhook secret must contain one or more non-empty strings.');
            }
            $secrets[] = trim($value);
        }
        if ($secrets === []) {
            throw new \InvalidArgumentException('Inbound webhook secret must contain one or more non-empty strings.');
        }
        if ($this->config->isProduction()
            && array_any($secrets, static fn(string $value): bool => hash_equals('change-me', $value))
        ) {
            throw new \LogicException('Production inbound webhook profiles must replace the default secret.');
        }

        return $single ? $secrets[0] : $secrets;
    }
}
