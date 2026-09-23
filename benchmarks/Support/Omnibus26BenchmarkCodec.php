<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use Infocyph\Omnibus\Serialization\MessageCodec;

final readonly class Omnibus26BenchmarkCodec implements MessageCodec
{
    public function alias(): string
    {
        return 'foundation.benchmark.omnibus26.v1';
    }

    public function decode(array $payload): object
    {
        return new Omnibus26BenchmarkMessage((string) ($payload['value'] ?? ''));
    }

    public function encode(object $message): array
    {
        if (!$message instanceof Omnibus26BenchmarkMessage) {
            throw new \InvalidArgumentException('Unexpected benchmark message.');
        }

        return ['value' => $message->value];
    }

    public function type(): string
    {
        return Omnibus26BenchmarkMessage::class;
    }
}
