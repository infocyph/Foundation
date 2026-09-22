<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Integration\DBLayer\AfterCommitDispatcher;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerFailureStore;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerTransport;
use Infocyph\Omnibus\Integration\DBLayer\DBLayerWorkflowStore;
use Infocyph\Omnibus\MessageBus;
use Infocyph\Omnibus\Serialization\CoreStampCodecs;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\Omnibus\Serialization\JsonEnvelopeSerializer;
use Infocyph\Omnibus\Serialization\MessageCodec;
use Infocyph\Omnibus\Serialization\MessageCodecRegistry;
use Infocyph\Omnibus\Serialization\StampCodec;
use Infocyph\Omnibus\Serialization\StampCodecRegistry;

final readonly class OmnibusDurableFactory
{
    public function __construct(
        private ConfigRepository $config,
        private DBLayerFactory $database,
        private MessagingRuntimeResolver $resolver,
        private SystemClock $clock,
    ) {}

    public function afterCommit(MessageBus $bus): AfterCommitDispatcher
    {
        return new AfterCommitDispatcher(
            $this->database->connection($this->connectionName()),
            $bus,
        );
    }

    public function failureStore(EnvelopeSerializer $serializer): DBLayerFailureStore
    {
        return new DBLayerFailureStore(
            $this->database->infrastructureConnection($this->connectionName()),
            $serializer,
            $this->table('failures', 'omnibus_failures'),
            $this->clock,
        );
    }

    public function serializer(): JsonEnvelopeSerializer
    {
        return new JsonEnvelopeSerializer(
            new MessageCodecRegistry($this->messageCodecs()),
            new StampCodecRegistry([
                ...CoreStampCodecs::all(),
                ...$this->stampCodecs(),
            ]),
            ValueNormalizer::int($this->config->get('messaging.serialization.maximum_bytes'), 262_144),
            ValueNormalizer::int($this->config->get('messaging.serialization.maximum_depth'), 32),
            ValueNormalizer::int($this->config->get('messaging.serialization.maximum_stamps'), 64),
        );
    }

    public function transport(EnvelopeSerializer $serializer): DBLayerTransport
    {
        return new DBLayerTransport(
            $this->database->infrastructureConnection($this->connectionName()),
            $serializer,
            $this->clock,
            $this->table('messages', 'omnibus_messages'),
        );
    }

    public function workflowStore(EnvelopeSerializer $serializer): DBLayerWorkflowStore
    {
        return new DBLayerWorkflowStore(
            $this->database->infrastructureConnection($this->connectionName()),
            $serializer,
            $this->table('workflows', 'omnibus_workflows'),
            $this->table('workflow_items', 'omnibus_workflow_items'),
            $this->clock,
        );
    }

    private function connectionName(): ?string
    {
        $connection = $this->config->get('messaging.durable.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /** @return list<MessageCodec> */
    private function messageCodecs(): array
    {
        $configured = $this->config->get('messaging.serialization.message_codecs', []);
        if (!is_array($configured)) {
            throw new ConfigurationException('messaging.serialization.message_codecs must be an ordered list.');
        }

        $codecs = [];
        foreach ($configured as $definition) {
            $codec = $this->resolver->service($definition);
            if (!$codec instanceof MessageCodec) {
                throw new ConfigurationException(
                    'Every messaging.serialization.message_codecs entry must resolve to Omnibus MessageCodec.',
                );
            }
            $codecs[] = $codec;
        }

        return $codecs;
    }

    /** @return list<StampCodec> */
    private function stampCodecs(): array
    {
        $configured = $this->config->get('messaging.serialization.stamp_codecs', []);
        if (!is_array($configured)) {
            throw new ConfigurationException('messaging.serialization.stamp_codecs must be an ordered list.');
        }

        $codecs = [];
        foreach ($configured as $definition) {
            $codec = $this->resolver->service($definition);
            if (!$codec instanceof StampCodec) {
                throw new ConfigurationException(
                    'Every messaging.serialization.stamp_codecs entry must resolve to Omnibus StampCodec.',
                );
            }
            $codecs[] = $codec;
        }

        return $codecs;
    }

    private function table(string $name, string $default): string
    {
        return ValueNormalizer::string(
            $this->config->get('messaging.durable.tables.' . $name),
            $default,
        );
    }
}
