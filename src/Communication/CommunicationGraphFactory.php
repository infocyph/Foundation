<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Communication;

use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\WebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;

final class CommunicationGraphFactory
{
    public static function httpConfig(CommunicationProfiles $profiles): HttpClientConfig
    {
        return $profiles->httpConfig();
    }

    public static function http(CommunicationProfiles $profiles): HttpClient
    {
        return $profiles->http();
    }

    public static function webhookSender(CommunicationProfiles $profiles): WebhookSender
    {
        return $profiles->webhookSender();
    }

    public static function webhookVerifier(CommunicationProfiles $profiles): WebhookVerifier
    {
        return $profiles->webhookVerifier();
    }

    public static function webhookReceiver(
        CommunicationProfiles $profiles,
        ConfigRepository $config,
    ): WebhookReceiver {
        $profile = $config->get('communication.webhooks.default_inbound', 'default');
        $profile = is_string($profile) && trim($profile) !== '' ? trim($profile) : 'default';

        return $profiles->webhookReceiver($profile);
    }

    public static function protectedWebhookReceiver(
        CommunicationProfiles $profiles,
        ConfigRepository $config,
        CacheLayerFactory $cache,
    ): WebhookReceiver {
        $profile = $config->get('communication.webhooks.default_inbound', 'default');
        $profile = is_string($profile) && trim($profile) !== '' ? trim($profile) : 'default';
        $configured = $config->get('communication.webhooks.inbound.' . $profile, []);
        $configured = is_array($configured) ? $configured : [];
        $replay = ValueNormalizer::associativeArray($configured['replay'] ?? []);
        $store = $replay['store'] ?? $config->get('cache.default');
        $store = is_string($store) && trim($store) !== '' ? trim($store) : null;
        if ($store === null) {
            throw new \LogicException('Webhook replay protection requires a configured CacheLayer store.');
        }

        $ttl = max(1, ValueNormalizer::int($replay['ttl_seconds'] ?? null, 86_400));
        $replayStore = new CacheLayerWebhookReplayStore(
            $cache->make($store),
            $cache->lock($store),
        );

        return $profiles->webhookReceiver($profile, $replayStore, $ttl);
    }
}
