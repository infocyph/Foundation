<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Omnibus\Serialization\MessageCodec;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class FoundationReleaseCarryoverMessageCodec implements MessageCodec
{
    #[\Override]
    public function alias(): string
    {
        return 'foundation.omnibus26.durable-message.v1';
    }

    #[\Override]
    public function decode(array $payload): object
    {
        $value = $payload['value'] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException('Durable carryover value is invalid.');
        }

        return new FoundationReleaseCarryoverMessage($value);
    }

    #[\Override]
    public function encode(object $message): array
    {
        if (!$message instanceof FoundationReleaseCarryoverMessage) {
            throw new InvalidArgumentException('Unexpected carryover message type.');
        }

        return ['value' => $message->value];
    }

    #[\Override]
    public function type(): string
    {
        return FoundationReleaseCarryoverMessage::class;
    }
}
