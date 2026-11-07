<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use Infocyph\Omnibus\Serialization\MessageCodec;

final readonly class OmnibusBenchmarkCodec implements MessageCodec
{
    public function alias(): string
    {
        return 'foundation.benchmark.omnibus.v1';
    }

    public function decode(array $payload): object
    {
        return new OmnibusBenchmarkMessage((string) ($payload['value'] ?? ''));
    }

    public function encode(object $message): array
    {
        if (!$message instanceof OmnibusBenchmarkMessage) {
            throw new \InvalidArgumentException('Unexpected benchmark message.');
        }

        return ['value' => $message->value];
    }

    public function type(): string
    {
        return OmnibusBenchmarkMessage::class;
    }
}
