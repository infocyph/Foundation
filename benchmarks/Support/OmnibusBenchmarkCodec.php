<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use Infocyph\Omnibus\Serialization\MessageCodec;

final readonly class OmnibusBenchmarkCodec implements MessageCodec
{
    #[\Override]
    public function alias(): string
    {
        return 'foundation.benchmark.omnibus.v1';
    }

    #[\Override]
    public function decode(array $payload): object
    {
        return new OmnibusBenchmarkMessage((string) ($payload['value'] ?? ''));
    }

    #[\Override]
    public function encode(object $message): array
    {
        if (!$message instanceof OmnibusBenchmarkMessage) {
            throw new \InvalidArgumentException('Unexpected benchmark message.');
        }

        return ['value' => $message->value];
    }

    #[\Override]
    public function type(): string
    {
        return OmnibusBenchmarkMessage::class;
    }
}
